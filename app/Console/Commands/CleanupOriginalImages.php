<?php

namespace App\Console\Commands;

use App\Models\TourFile;
use App\Models\VendorPortfolioImage;
use App\Models\FeatureSheetImage;
use App\Services\ImageResizeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupOriginalImages extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'images:cleanup-originals 
                            {--days=30 : Number of days after which to delete originals}
                            {--dry-run : Preview what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Archive original images (30MB+) and replace them with print-optimized variants (4k) after 30 days';

    /**
     * Execute the console command.
     */
    public function handle(ImageResizeService $resizer): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("Media Retention Policy: Archiving originals created before: {$cutoffDate->toDateTimeString()}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        $summary = [
            'TourFile' => ['deleted' => 0, 'skipped' => 0, 'errors' => 0],
            'VendorPortfolioImage' => ['deleted' => 0, 'skipped' => 0, 'errors' => 0],
            'FeatureSheetImage' => ['deleted' => 0, 'skipped' => 0, 'errors' => 0],
        ];

        // 1. Process TourFile
        $this->processModel(
            TourFile::class,
            $cutoffDate,
            'file_path',
            ['type' => 'photo', 'is_processing' => false],
            $resizer,
            $dryRun,
            $summary['TourFile']
        );

        // 2. Process VendorPortfolioImage
        $this->processModel(
            VendorPortfolioImage::class,
            $cutoffDate,
            'image_path',
            ['image_type' => 'uploaded', 'is_processing' => false],
            $resizer,
            $dryRun,
            $summary['VendorPortfolioImage']
        );

        // 3. Process FeatureSheetImage
        $this->processModel(
            FeatureSheetImage::class,
            $cutoffDate,
            'storage_path',
            ['is_processing' => false],
            $resizer,
            $dryRun,
            $summary['FeatureSheetImage']
        );

        $this->newLine();
        $this->info("Final Summary:");
        foreach ($summary as $model => $stats) {
            $this->info("  {$model}: Deleted: {$stats['deleted']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");
        }

        return Command::SUCCESS;
    }

    protected function processModel(
        string $modelClass,
        Carbon $cutoffDate,
        string $pathField,
        array $filters,
        ImageResizeService $resizer,
        bool $dryRun,
        array &$stats
    ): void {
        $query = $modelClass::where('created_at', '<', $cutoffDate)
            ->whereNotNull('variants');

        foreach ($filters as $field => $value) {
            $query->where($field, $value);
        }

        $items = $query->get();
        
        if ($items->isEmpty()) {
            return;
        }

        $this->info("Processing " . class_basename($modelClass) . " ({$items->count()} items)...");
        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        foreach ($items as $item) {
            try {
                $originalPath = $item->{$pathField};
                $variants = $item->variants ?? [];

                // 1. Skip if already downsized (path points to print variant)
                if (isset($variants['print']) && $originalPath === $variants['print']) {
                    $stats['skipped']++;
                    $bar->advance();
                    continue;
                }

                // 2. Verify original exists
                if (!Storage::disk('s3')->exists($originalPath)) {
                    $stats['skipped']++;
                    $bar->advance();
                    continue;
                }

                // 3. Ensure 'print' variant exists
                if (!isset($variants['print']) || !Storage::disk('s3')->exists($variants['print'])) {
                    if (!$dryRun) {
                        $this->info("\nGenerating print variant for {$item->uuid}...");
                        // Generate only the missing print variant
                        $newVariants = $resizer->processImage($originalPath, [
                            'print' => ['width' => 4000, 'format' => 'jpg', 'quality' => 90, 'watermark' => false]
                        ]);
                        $variants = array_merge($variants, $newVariants);
                        $item->update(['variants' => $variants]);
                    } else {
                        $this->warn("\n[Dry Run] Would generate print variant for {$item->uuid}");
                    }
                }

                if (!$dryRun) {
                    // Double check print variant exists after generation attempt
                    if (!isset($variants['print']) || !Storage::disk('s3')->exists($variants['print'])) {
                        throw new \Exception("Print variant missing or failed to generate.");
                    }

                    // 4. Update path to print variant BEFORE deleting original
                    $item->update([$pathField => $variants['print']]);

                    // 5. Delete original
                    Storage::disk('s3')->delete($originalPath);
                    
                    Log::info("Archived original image: {$originalPath} -> Replaced with print variant: {$variants['print']}");
                    $stats['deleted']++;
                } else {
                    $stats['deleted']++; // Count as "would delete"
                }

            } catch (\Exception $e) {
                $this->error("\nError archiving {$item->uuid}: " . $e->getMessage());
                Log::error("Archive error for {$item->uuid}: " . $e->getMessage());
                $stats['errors']++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }
}
