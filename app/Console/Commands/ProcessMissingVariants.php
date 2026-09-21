<?php

namespace App\Console\Commands;

use App\Models\TourFile;
use App\Models\FeatureSheetImage;
use App\Models\VendorPortfolioImage;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\ProcessFeatureSheetImage;
use App\Jobs\ProcessPortfolioImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessMissingVariants extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:process-missing 
                            {--limit=100 : Maximum number of images to process per run}
                            {--delay=1 : Seconds to delay between dispatching jobs}
                            {--type=all : Types to process (tour-file, feature-sheet, portfolio, all)}
                            {--force : Force re-processing even if variants exist}
                            {--dry-run : Just show what would be processed}';

    /**
     * The console command description.
     */
    protected $description = 'Find images with missing variants and trigger processing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('delay');
        $type = $this->option('type');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $queueName = env('MEDIA_PROCESSING_QUEUE', 'image-processing');

        $this->info("Starting missing variants search (Limit: {$limit}, Type: {$type})");
        if ($dryRun)
            $this->warn("DRY RUN MODE ENABLED");

        $processedCount = 0;

        // 1. Tour Files
        if ($type === 'all' || $type === 'tour-file') {
            $query = TourFile::where('type', 'photo');
            if (!$force) {
                $query->whereNull('variants')->orWhere('variants', '');
            }

            $items = $query->limit($limit - $processedCount)->get();
            foreach ($items as $item) {
                if ($processedCount >= $limit)
                    break;

                $this->line("Processing TourFile: {$item->uuid}");
                if (!$dryRun) {
                    $item->update(['is_processing' => true]);
                    ProcessUploadedImage::dispatch($item)
                        ->delay(now()->addSeconds($processedCount * $delay))
                        ->onQueue($queueName);
                }
                $processedCount++;
            }
        }

        // 2. Feature Sheet Images
        if (($type === 'all' || $type === 'feature-sheet') && $processedCount < $limit) {
            $query = FeatureSheetImage::query();
            if (!$force) {
                $query->whereNull('variants')->orWhere('variants', '');
            }

            $items = $query->limit($limit - $processedCount)->get();
            foreach ($items as $item) {
                if ($processedCount >= $limit)
                    break;

                $this->line("Processing FeatureSheetImage: {$item->uuid}");
                if (!$dryRun) {
                    $item->update(['is_processing' => true]);
                    ProcessFeatureSheetImage::dispatch($item)
                        ->delay(now()->addSeconds($processedCount * $delay))
                        ->onQueue($queueName);
                }
                $processedCount++;
            }
        }

        // 3. Vendor Portfolio Images
        if (($type === 'all' || $type === 'portfolio') && $processedCount < $limit) {
            $query = VendorPortfolioImage::where('image_type', 'uploaded');
            if (!$force) {
                $query->whereNull('variants')->orWhere('variants', '');
            }

            $items = $query->limit($limit - $processedCount)->get();
            foreach ($items as $item) {
                if ($processedCount >= $limit)
                    break;

                $this->line("Processing PortfolioImage: {$item->uuid}");
                if (!$dryRun) {
                    $item->update(['is_processing' => true]);
                    ProcessPortfolioImage::dispatch($item)
                        ->delay(now()->addSeconds($processedCount * $delay))
                        ->onQueue($queueName);
                }
                $processedCount++;
            }
        }

        $this->info("Finished. Total identifying: {$processedCount}");
        return Command::SUCCESS;
    }
}
