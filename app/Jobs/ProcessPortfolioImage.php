<?php

namespace App\Jobs;

use App\Models\VendorPortfolioImage;
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

class ProcessPortfolioImage implements ShouldQueue, ShouldBeUnique
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
    public int $timeout = 420;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * The number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    public function __construct(
        public VendorPortfolioImage $portfolioImage
    ) {
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->portfolioImage->uuid))->releaseAfter(60)
        ];
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->portfolioImage->uuid;
    }

    public function handle(ImageResizeService $resizer): void
    {
        $attempt = $this->attempts();
        Log::info("[PORTFOLIO-JOB] START uuid={$this->portfolioImage->uuid} path={$this->portfolioImage->image_path} attempt={$attempt}");

        try {
            // Process the image and generate variants
            $variants = $resizer->processImage($this->portfolioImage->image_path);

            // Update database with variant paths
            $this->portfolioImage->update([
                'variants' => $variants,
                'is_processing' => false,
            ]);

            if (empty($variants)) {
                Log::warning("[PORTFOLIO-JOB] SKIPPED variants were empty for uuid={$this->portfolioImage->uuid}");
            }

            Log::info("[PORTFOLIO-JOB] COMPLETE uuid={$this->portfolioImage->uuid} variants=" . implode(',', array_keys($variants)));

        } catch (Throwable $e) {
            $memory = round(memory_get_usage() / 1024 / 1024, 2);
            Log::error("[PORTFOLIO-JOB] FAILED uuid={$this->portfolioImage->uuid} error=" . $e->getMessage() . " memory={$memory}MB");
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Portfolio image processing job failed permanently: {$this->portfolioImage->uuid}", [
            'error' => $exception?->getMessage(),
        ]);

        // Mark as not processing
        $this->portfolioImage->update(['is_processing' => false]);
    }
}
