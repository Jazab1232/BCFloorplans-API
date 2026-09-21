<?php

namespace App\Jobs;

use App\Models\MediaDownloadJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CleanupMediaDownloadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("CleanupMediaDownloadsJob: Starting expired downloads cleanup.");

        $expiredJobs = MediaDownloadJob::where('expires_at', '<', now())
            ->whereNotNull('zip_path')
            ->get();

        $count = 0;
        foreach ($expiredJobs as $job) {
            try {
                // Delete from S3
                if (Storage::disk('s3')->exists($job->zip_path)) {
                    Storage::disk('s3')->delete($job->zip_path);
                }

                // Delete the DB record
                $job->delete();
                $count++;
            } catch (\Exception $e) {
                Log::error("CleanupMediaDownloadsJob: Failed to cleanup job {$job->uuid}: " . $e->getMessage());
            }
        }

        Log::info("CleanupMediaDownloadsJob: Cleaned up {$count} expired download jobs.");
    }
}
