<?php

namespace App\Console\Commands;

use App\Models\TourFile;
use App\Models\FeatureSheetImage;
use App\Models\VendorPortfolioImage;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\ProcessFeatureSheetImage;
use App\Jobs\ProcessPortfolioImage;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFailedMedia extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'media:retry-failed 
                            {--older-than=30 : Minutes after which a processing file is considered stuck}
                            {--dry-run : Preview what would be retried}';

    /**
     * The console command description.
     */
    protected $description = 'Find and retry media files stuck in processing state';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = (int) $this->option('older-than');
        $dryRun = $this->option('dry-run');
        $cutoffTime = Carbon::now()->subMinutes($minutes);
        $queueName = env('MEDIA_PROCESSING_QUEUE', 'image-processing');

        $this->info("Looking for stuck media processed before: {$cutoffTime->toDateTimeString()}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No jobs will be dispatched');
        }

        // 1. Check TourFiles
        $stuckTourFiles = TourFile::where('is_processing', true)
            ->where('updated_at', '<', $cutoffTime)
            ->get();

        $this->info("Found {$stuckTourFiles->count()} stuck TourFiles");
        foreach ($stuckTourFiles as $file) {
            $this->line("- TourFile: {$file->uuid} (Updated: {$file->updated_at})");
            if (!$dryRun) {
                ProcessUploadedImage::dispatch($file)->onQueue($queueName);
                Log::info("[MEDIA-RETRY] Re-dispatched TourFile: {$file->uuid}");
            }
        }

        // 2. Check FeatureSheetImages
        $stuckFSImages = FeatureSheetImage::where('is_processing', true)
            ->where('updated_at', '<', $cutoffTime)
            ->get();

        $this->info("Found {$stuckFSImages->count()} stuck FeatureSheetImages");
        foreach ($stuckFSImages as $file) {
            $this->line("- FeatureSheetImage: {$file->uuid} (Updated: {$file->updated_at})");
            if (!$dryRun) {
                ProcessFeatureSheetImage::dispatch($file)->onQueue($queueName);
                Log::info("[MEDIA-RETRY] Re-dispatched FeatureSheetImage: {$file->uuid}");
            }
        }

        // 3. Check VendorPortfolioImages
        $stuckPortfolioImages = VendorPortfolioImage::where('is_processing', true)
            ->where('updated_at', '<', $cutoffTime)
            ->get();

        $this->info("Found {$stuckPortfolioImages->count()} stuck VendorPortfolioImages");
        foreach ($stuckPortfolioImages as $file) {
            $this->line("- VendorPortfolioImage: {$file->uuid} (Updated: {$file->updated_at})");
            if (!$dryRun) {
                ProcessPortfolioImage::dispatch($file)->onQueue($queueName);
                Log::info("[MEDIA-RETRY] Re-dispatched VendorPortfolioImage: {$file->uuid}");
            }
        }

        $this->info('Done.');
        return Command::SUCCESS;
    }
}
