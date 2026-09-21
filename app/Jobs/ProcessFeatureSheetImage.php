<?php

namespace App\Jobs;

use App\Models\FeatureSheetImage;
use App\Services\ImageResizeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessFeatureSheetImage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Maximum time in seconds the job can run.
     */
    public int $timeout = 420; // 7 minutes (adjusted for 4K images)

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * The number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    public function __construct(
        public FeatureSheetImage $featureSheetImage
    ) {
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->featureSheetImage->uuid))->releaseAfter(60)
        ];
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->featureSheetImage->uuid;
    }

    public function handle(ImageResizeService $resizer): void
    {
        $attempt = $this->attempts();
        Log::info("[MEDIA-JOB] START (FS) uuid={$this->featureSheetImage->uuid} path={$this->featureSheetImage->storage_path} attempt={$attempt}");

        try {
            // Generate size variants without watermarking (personal media uploaded by agent/admin)
            $variants = $resizer->processImage($this->featureSheetImage->storage_path, null, true);

            // Update database with variant paths
            $this->featureSheetImage->update([
                'variants' => $variants,
                'is_processing' => false,
            ]);

            Log::info("[MEDIA-JOB] COMPLETE (FS) uuid={$this->featureSheetImage->uuid} variants=" . implode(',', array_keys($variants)));

        } catch (Throwable $e) {
            $memory = round(memory_get_usage() / 1024 / 1024, 2);
            Log::error("[MEDIA-JOB] FAILED (FS) uuid={$this->featureSheetImage->uuid} error=" . $e->getMessage() . " memory={$memory}MB");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Feature sheet image processing job failed permanently: {$this->featureSheetImage->uuid}", [
            'error' => $exception?->getMessage(),
        ]);

        // Mark as not processing but leave variants null to indicate failure
        $this->featureSheetImage->update(['is_processing' => false]);
    }
}
