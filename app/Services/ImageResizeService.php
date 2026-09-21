<?php

namespace App\Services;

use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImageResizeService
{
    /**
     * Default size variants if none configured in admin settings
     * Format: 'variant_name' => ['width' => max_width, 'format' => output_format, 'quality' => 1-100]
     */
    protected array $defaultVariants = [
        'thumb' => ['width' => 300, 'format' => 'webp', 'quality' => 75, 'watermark' => true],
        'slider' => ['width' => 800, 'format' => 'webp', 'quality' => 80, 'watermark' => true],
        'landing' => ['width' => 1920, 'format' => 'webp', 'quality' => 85, 'watermark' => true],
        'popup' => ['width' => 1680, 'format' => 'webp', 'quality' => 85, 'watermark' => true],
        'print' => ['width' => 4000, 'format' => 'jpg', 'quality' => 90, 'watermark' => false],
    ];

    public function __construct(
        protected WatermarkService $watermarkService
    ) {
    }

    /**
     * Process an image and generate all size variants
     *
     * @param string $originalS3Key S3 key of the original image
     * @param array|null $customVariants Optional custom variant configurations
     * @param bool $skipWatermark Whether to skip applying the watermark
     * @param string|null $watermarkText Optional custom text for watermark
     * @return array Map of variant names to S3 keys
     */
    public function processImage(string $originalS3Key, ?array $customVariants = null, bool $skipWatermark = false, ?string $watermarkText = null): array
    {
        $variants = $customVariants ?? $this->defaultVariants;
        $generatedVariants = [];

        // Check if file is SVG vector graphic (e.g. logos, brandings, thumbnails)
        $extension = strtolower(pathinfo($originalS3Key, PATHINFO_EXTENSION));
        if ($extension === 'svg' || str_contains(strtolower($originalS3Key), '.svg')) {
            Log::info("[MEDIA-PROCESS] SVG vector graphic detected for {$originalS3Key}. Bypassing raster resizing.");
            foreach ($variants as $variantName => $config) {
                $generatedVariants[$variantName] = $originalS3Key;
            }
            return $generatedVariants;
        }

        try {
            $startTime = microtime(true);
            $initialMemory = round(memory_get_usage() / 1024 / 1024, 2);
            Log::debug("[MEDIA-PROCESS] START s3_key={$originalS3Key} | memory={$initialMemory}MB");

            // Download original from S3 to temp file using streams to save memory
            if (!Storage::disk('s3')->exists($originalS3Key)) {
                throw new \Exception("File not found on S3: {$originalS3Key}");
            }

            $tempOriginal = tempnam(sys_get_temp_dir(), 'img_original_');

            // Use streaming to download
            $readStream = Storage::disk('s3')->readStream($originalS3Key);
            $writeStream = fopen($tempOriginal, 'w');
            stream_copy_to_stream($readStream, $writeStream);
            fclose($writeStream);
            if (is_resource($readStream)) {
                fclose($readStream);
            }

            $fileSize = round(filesize($tempOriginal) / 1024 / 1024, 2);
            $afterDownloadMemory = round(memory_get_usage() / 1024 / 1024, 2);
            Log::debug("[MEDIA-PROCESS] DOWNLOADED temp_size={$fileSize}MB | memory={$afterDownloadMemory}MB");

            // Parse the S3 key to build variant paths
            // Original: tours/{id}/photos/originals/{uuid}.jpg
            // Variant:  tours/{id}/photos/variants/{variant_name}/{uuid}.webp
            $pathInfo = pathinfo($originalS3Key);
            $origExtension = strtolower($pathInfo['extension'] ?? '');
            if (!$origExtension || $origExtension === '') {
                $detectedMime = @mime_content_type($tempOriginal);
                if ($detectedMime === 'image/png') {
                    $origExtension = 'png';
                }
            }
            $isPng = ($origExtension === 'png');

            // Handle both "originals" directory structure and others
            $basePath = str_replace('/originals', '/variants', $pathInfo['dirname']);
            // If replace didn't happen (key didn't have /originals), append /variants
            if ($basePath === $pathInfo['dirname']) {
                $basePath .= '/variants';
            }

            $filename = $pathInfo['filename'];

            foreach ($variants as $variantName => $config) {
                try {
                    $this->checkMemoryPressure();

                    $vStartTime = microtime(true);
                    $variantKey = $this->generateVariant(
                        $tempOriginal,
                        $basePath,
                        $filename,
                        $variantName,
                        $config,
                        $skipWatermark,
                        $watermarkText,
                        $isPng
                    );
                    $generatedVariants[$variantName] = $variantKey;

                    $vDuration = round(microtime(true) - $vStartTime, 2);
                    $vMemory = round(memory_get_usage() / 1024 / 1024, 2);
                    Log::debug("[MEDIA-PROCESS] VARIANT={$variantName} COMPLETE | time={$vDuration}s | memory={$vMemory}MB");

                    // Force cleanup after each variant
                    gc_collect_cycles();
                } catch (\Exception $e) {
                    Log::error("[MEDIA-PROCESS] Failed to generate {$variantName} variant for {$filename}: " . $e->getMessage(), [
                        'exception' => $e,
                        's3_key' => $originalS3Key
                    ]);
                }
            }

            $totalDuration = round(microtime(true) - $startTime, 2);
            $peakMemory = round(memory_get_peak_usage() / 1024 / 1024, 2);
            Log::debug("[MEDIA-PROCESS] SUCCESS s3_key={$originalS3Key} | duration={$totalDuration}s | peak_memory={$peakMemory}MB");

            if (empty($generatedVariants)) {
                Log::error("[MEDIA-PROCESS] NO VARIANTS GENERATED for {$originalS3Key}. This image will be broken in the front-end.");
            }

            // Clean up temp file
            @unlink($tempOriginal);

        } catch (\Exception $e) {
            Log::error("[MEDIA-PROCESS] Image processing failed for {$originalS3Key}: " . $e->getMessage());
            throw $e;
        } finally {
            // Guarantee cleanup of temp file
            if (isset($tempOriginal) && file_exists($tempOriginal)) {
                @unlink($tempOriginal);
                Log::debug("[MEDIA-PROCESS] CLEANUP temp file deleted");
            }
        }

        return $generatedVariants;
    }

    /**
     * Generate a single size variant
     */
    protected function generateVariant(
        string $tempOriginal,
        string $basePath,
        string $filename,
        string $variantName,
        array $config,
        bool $skipWatermark = false,
        ?string $watermarkText = null,
        bool $isPng = false
    ): string {
        $width = $config['width'];
        $format = $config['format'] ?? 'webp';
        $quality = $config['quality'] ?? 80;
        $shouldWatermark = $config['watermark'] ?? true;

        // Fallback check if $isPng was not passed
        if (!$isPng) {
            $detectedMime = @mime_content_type($tempOriginal);
            $isPng = ($detectedMime === 'image/png');
        }

        // Load and resize image
        $preLoadMemory = round(memory_get_usage() / 1024 / 1024, 2);
        Log::debug("[MEDIA-PROCESS] VARIANT={$variantName} LOAD pre_memory={$preLoadMemory}MB");

        $image = Image::read($tempOriginal);
        $originalWidth = $image->width();
        $originalHeight = $image->height();

        // Only resize if image is larger than target
        if ($originalWidth > $width) {
            $image->scaleDown(width: $width);
            $newWidth = $image->width();
            $newHeight = $image->height();
            Log::debug("[MEDIA-PROCESS] VARIANT={$variantName} RESIZED from {$originalWidth}x{$originalHeight} to {$newWidth}x{$newHeight}");
        } else {
            Log::debug("[MEDIA-PROCESS] VARIANT={$variantName} SKIPPED RESIZE (smaller than target)");
        }

        // Apply watermark if not skipped
        if (!$skipWatermark && $shouldWatermark) {
            $image = $this->watermarkService->apply($image, $watermarkText);
            Log::debug("[MEDIA-PROCESS] VARIANT={$variantName} WATERMARKED with text='{$watermarkText}'");
        } else {
            Log::debug("[MEDIA-PROCESS] VARIANT={$variantName} WATERMARK SKIPPED (skipWatermark=" . ($skipWatermark ? 'true' : 'false') . ", shouldWatermark=" . ($shouldWatermark ? 'true' : 'false') . ")");
        }

        // Preserve alpha transparency for PNG originals - never convert to JPG (which flattens alpha to solid black/white)
        if ($isPng) {
            if ($format === 'webp') {
                $targetFormat = 'webp';
                $encoded = (string) $image->toWebp($quality);
                $contentType = 'image/webp';
            } else {
                $targetFormat = 'png';
                $encoded = (string) $image->toPng();
                $contentType = 'image/png';
            }
        } elseif ($format === 'png') {
            $targetFormat = 'png';
            $encoded = (string) $image->toPng();
            $contentType = 'image/png';
        } elseif ($format === 'webp') {
            $targetFormat = 'webp';
            $encoded = (string) $image->toWebp($quality);
            $contentType = 'image/webp';
        } else {
            $targetFormat = ($format === 'jpeg') ? 'jpg' : $format;
            $encoded = (string) $image->toJpeg($quality);
            $contentType = 'image/jpeg';
        }

        // Build S3 key for variant
        $variantKey = "{$basePath}/{$variantName}/{$filename}.{$targetFormat}";

        // Upload to S3 with public visibility and explicit ContentType
        Storage::disk('s3')->put($variantKey, $encoded, [
            'visibility' => 'public',
            'ContentType' => $contentType
        ]);

        $encodedSize = round(strlen($encoded) / 1024 / 1024, 2);
        Log::debug("[MEDIA-PROCESS] VARIANT={$variantName} UPLOADED key={$variantKey} size={$encodedSize}MB (type={$contentType})");

        // Explicit cleanup of large objects
        unset($image);
        unset($encoded);

        return $variantKey;
    }

    /**
     * Check current memory usage and log a warning if high.
     * Can be expanded to pause or throw exception if too high.
     */
    public function checkMemoryPressure(): void
    {
        $limit = $this->getMemoryLimit();
        $current = memory_get_usage();
        $percent = ($current / $limit) * 100;

        if ($percent > (env('MEDIA_PROCESSING_MAX_MEMORY_PERCENT', 70))) {
            Log::warning("[MEDIA-PROCESS] HIGH MEMORY PRESSURE: " . round($percent, 2) . "% (" . round($current / 1024 / 1024, 2) . "MB). Throttling...");

            // GC and minor sleep to let system recover
            gc_collect_cycles();
            usleep(500000); // 500ms sleep for throttling
        }
    }

    /**
     * Helper to get PHP memory limit in bytes
     */
    public function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1')
            return 2 * 1024 * 1024 * 1024; // Assume 2GB if no limit

        $unit = strtolower(substr($limit, -1));
        $val = (int) $limit;

        switch ($unit) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * Delete all variants for an image
     */
    public function deleteVariants(array $variantPaths): void
    {
        foreach ($variantPaths as $path) {
            try {
                Storage::disk('s3')->delete($path);
            } catch (\Exception $e) {
                Log::warning("Failed to delete variant {$path}: " . $e->getMessage());
            }
        }
    }
}
