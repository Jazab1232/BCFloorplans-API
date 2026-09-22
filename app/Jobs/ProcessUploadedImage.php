<?php

namespace App\Jobs;

use App\Models\TourFile;
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

class ProcessUploadedImage implements ShouldQueue, ShouldBeUnique
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
        public TourFile $tourFile
    ) {
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->tourFile->uuid))->releaseAfter(60)
        ];
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->tourFile->uuid;
    }

    public function handle(ImageResizeService $resizer): void
    {
        $attempt = $this->attempts();
        Log::info("[MEDIA-JOB] START uuid={$this->tourFile->uuid} path={$this->tourFile->file_path} attempt={$attempt}");

        try {
            // Ensure file is an image (not video or pdf)
            if ($this->tourFile->type === 'video' || $this->tourFile->type === 'pdf') {
                Log::info("Skipping non-image file ({$this->tourFile->type}): {$this->tourFile->uuid}");
                $this->tourFile->update(['is_processing' => false]);
                return;
            }

            // Determine watermark text based on organization
            $watermarkText = 'Tojuco Solutions';
            $tour = $this->tourFile->tour;
            $order = $tour ? $tour->order : null;
            if ($order && $order->organization) {
                if ($order->organization->is_whitelabel && !empty(trim($order->organization->name ?? ''))) {
                    $watermarkText = trim($order->organization->name);
                }
            }

            // Determine if watermarking should be skipped (paid OR released before payment)
            $skipWatermark = (bool) $this->tourFile->is_paid || ($order && (bool) $order->release_media_before_payment);

            // Generate all size variants
            $variants = $resizer->processImage(
                $this->tourFile->file_path,
                null,
                $skipWatermark,
                $watermarkText
            );

            // Update database with variant paths
            $this->tourFile->update([
                'variants' => $variants,
                'is_processing' => false,
            ]);

            if (empty($variants)) {
                Log::warning("[MEDIA-JOB] SKIPPED variants were empty for uuid={$this->tourFile->uuid}");
            }

            Log::info("[MEDIA-JOB] COMPLETE uuid={$this->tourFile->uuid} variants=" . implode(',', array_keys($variants)));

        } catch (Throwable $e) {
            $memory = round(memory_get_usage() / 1024 / 1024, 2);
            Log::error("[MEDIA-JOB] FAILED uuid={$this->tourFile->uuid} error=" . $e->getMessage() . " memory={$memory}MB");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Image processing job failed permanently: {$this->tourFile->uuid}", [
            'error' => $exception?->getMessage(),
        ]);

        // Mark as not processing but leave variants null to indicate failure
        $this->tourFile->update(['is_processing' => false]);
    }
}
