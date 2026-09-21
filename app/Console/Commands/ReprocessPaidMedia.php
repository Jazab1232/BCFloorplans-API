<?php

namespace App\Console\Commands;

use App\Models\TourFile;
use App\Jobs\ProcessUploadedImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class ReprocessPaidMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:reprocess-paid 
                            {--dry-run : Only list the files that will be reprocessed}
                            {--ids= : Specific TourFile ID or comma-separated list of IDs to reprocess}
                            {--limit= : Max number of files to process}
                            {--delay=0 : Delay in seconds between each job dispatch to prevent CPU/memory spikes}
                            {--force : Force reprocessing even if the file was recently queued}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocess variants for all paid tour photos to remove watermarks';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $ids = $this->option('ids');
        $limit = $this->option('limit');
        $delay = (int) $this->option('delay');
        $force = $this->option('force');

        $this->info("Scanning for eligible tour photos...");

        $query = TourFile::where('type', 'photo');

        if ($ids) {
            $idArray = array_filter(array_map('intval', explode(',', $ids)));
            if (empty($idArray)) {
                $this->error("Invalid IDs provided.");
                return 1;
            }
            $query->whereIn('id', $idArray);
        } else {
            // Find photos belonging to PAID services or having no service
            $query->where(function($q) {
                $q->whereHas('tour.orders.services', function($subQuery) {
                    $subQuery->whereColumn('order_services.service_id', 'tour_files.service_id')
                             ->where('order_services.payment_status', 'PAID');
                })
                ->orWhereNull('service_id');
            });
        }

        $tourFiles = $query->get();

        $pendingFiles = collect();
        $processingCount = 0;
        $completedCount = 0;

        foreach ($tourFiles as $file) {
            $status = Cache::get("reprocessing_file_{$file->id}");

            if (is_numeric($status)) {
                // If it has a timestamp, check if it was updated after being queued
                // We add a tiny 1-second buffer for db/php precision alignment
                if ($file->updated_at && $file->updated_at->timestamp >= ($status - 1)) {
                    Cache::put("reprocessing_file_{$file->id}", 'completed', now()->addDays(30));
                    $status = 'completed';
                }
            }

            if ($status === 'completed') {
                $completedCount++;
            } elseif (is_numeric($status)) {
                $processingCount++;
            } else {
                $pendingFiles->push($file);
            }
        }

        $totalCount = $tourFiles->count();
        $pendingCount = $pendingFiles->count();
        
        $defaultQueueSize = Queue::size();
        $imageQueueSize = Queue::size('image-processing');

        $this->info("Reprocessing Status:");
        $this->line(" - Total Photos: {$totalCount}");
        $this->line(" - Pending Queueing: {$pendingCount}");
        $this->line(" - In Queue / Processing (Cached): {$processingCount}");
        $this->line(" - Completed Reprocessing (Cached): {$completedCount}");
        $this->line(" - Actual Queue Size (default): {$defaultQueueSize}");
        $this->line(" - Actual Queue Size (image-processing): {$imageQueueSize}");
        $this->newLine();

        if ($ids || $force) {
            $filesToProcess = $tourFiles;
        } else {
            $filesToProcess = $pendingFiles;
        }

        // Apply limit
        if ($limit) {
            $filesToProcess = $filesToProcess->take(intval($limit));
        }

        $count = $filesToProcess->count();
        $this->info("Found {$count} photo(s) selected for this run.");

        if ($count === 0) {
            return 0;
        }

        if ($dryRun) {
            $this->warn("DRY RUN MODE: Listing selected files:");
            foreach ($filesToProcess as $file) {
                $this->line(" - ID: {$file->id} | Name: {$file->name} | Path: {$file->file_path}");
            }
            return 0;
        }

        $this->info("Dispatching reprocessing jobs...");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $index = 0;
        foreach ($filesToProcess as $file) {
            // Mark as queued/processing in cache with current timestamp
            Cache::put("reprocessing_file_{$file->id}", now()->timestamp, now()->addHours(2));

            if ($delay > 0) {
                // Stagger dispatches with increasing delay
                ProcessUploadedImage::dispatch($file)->delay(now()->addSeconds($index * $delay));
            } else {
                ProcessUploadedImage::dispatch($file);
            }
            $index++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Successfully dispatched {$count} reprocessing jobs. Make sure queue worker is running!");

        return 0;
    }
}



