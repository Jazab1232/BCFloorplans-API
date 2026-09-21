<?php

namespace App\Jobs;

use App\Models\MediaDownloadJob;
use App\Models\TourFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use App\Services\ImageResizeService;
use App\Services\SettingsService;
use ZipArchive;
use Throwable;

class ProcessMediaDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    protected array $tempFiles = [];
    protected array $directDownloads = [];

    /**
     * Create a new job instance.
     * @param MediaDownloadJob $downloadJob
     * @param array $fileData Array of uuid => size mapping
     */
    public function __construct(
        protected MediaDownloadJob $downloadJob,
        protected array $fileData
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(ImageResizeService $resizer): void
    {
        $this->downloadJob->update(['status' => 'processing']);
        $tmpZip = tempnam(sys_get_temp_dir(), 'bcf_down_');
        $zip = new ZipArchive();

        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Could not create temp ZIP file.");
        }

        try {
            $uuids = array_keys($this->fileData);
            $files = TourFile::whereIn('uuid', $uuids)
                ->with(['tour.orders.property', 'tour.orders.organization'])
                ->orderBy('sort_order', 'asc')
                ->get();
            $this->downloadJob->update(['file_count' => $files->count()]);

            $firstFile = $files->first();
            $propertyAddress = 'Property';
            $mlsNumber = null;
            $orgUuid = null;
            if ($firstFile && $firstFile->tour && $firstFile->tour->orders) {
                if ($firstFile->tour->orders->property) {
                    $rawAddress = $firstFile->tour->orders->property->address ?: '';
                    $clean = preg_replace('/[^A-Za-z0-9]/', '', $rawAddress);
                    if (!empty($clean)) {
                        $propertyAddress = $clean;
                    }

                    $rawMls = $firstFile->tour->orders->property->mls_number ?: $firstFile->tour->orders->property->mls_property;
                    if (!empty($rawMls)) {
                        $cleanMls = preg_replace('/[^A-Za-z0-9]/', '', $rawMls);
                        if (!empty($cleanMls)) {
                            $mlsNumber = $cleanMls;
                        }
                    }
                }
                if ($firstFile->tour->orders->organization) {
                    $orgUuid = $firstFile->tour->orders->organization->uuid;
                }
            }

            // Fetch organization photo size settings with safe global fallback
            $photoSizes = [];
            try {
                $settingsService = app(SettingsService::class);
                $mediaSettings = $settingsService->get($orgUuid, 'media_settings');
                if (isset($mediaSettings['photos']) && is_array($mediaSettings['photos'])) {
                    $photoSizes = $mediaSettings['photos'];
                }
            } catch (\Throwable $e) {
                Log::warning("ProcessMediaDownloadJob: Could not load media_settings for org {$orgUuid}: " . $e->getMessage());
            }

            $uniqueSizes = array_unique(array_values($this->fileData));
            $mediaSize = 'Media';
            if (count($uniqueSizes) === 1) {
                $mediaSize = match (strtolower($uniqueSizes[0])) {
                    'mls' => 'MLS',
                    'large' => 'Large',
                    'small' => 'Small',
                    'original' => 'Original',
                    default => ucfirst($uniqueSizes[0])
                };
            }

            $types = $files->pluck('type')->unique()->toArray();
            $mediaType = 'Media';
            if (count($types) === 1 && $types[0] === 'photo') {
                $mediaType = 'HDRPhotos';
            } elseif (count($types) === 1 && str_contains($types[0], 'video')) {
                $mediaType = 'Videos';
            }

            $zipFileName = "{$propertyAddress}-{$mediaType}-{$mediaSize}.zip";

            foreach ($files as $index => $file) {
                try {
                    $size = $this->fileData[$file->uuid] ?? 'original';
                    $this->addFileToZip($zip, $file, $resizer, $size, $index, $propertyAddress, $mlsNumber, $photoSizes);
                    $this->downloadJob->update(['processed_count' => $index + 1]);

                    // Optimization: Garbage collection after each file
                    if ($index % 5 === 0) {
                        $resizer->checkMemoryPressure();
                    }
                } catch (Throwable $e) {
                    Log::warning("ProcessMediaDownloadJob: Failed to add file {$file->uuid} to ZIP: " . $e->getMessage());
                }
            }

            $zipFileCount = $zip->numFiles;
            $zip->close();
            $this->cleanupTempFiles();

            // Store the ZIP in S3 only if it contains files
            $s3Path = null;
            if ($zipFileCount > 0 && file_exists($tmpZip)) {
                $timestamp = now()->format('Y-m-d-His');
                $s3Path = "temp-downloads/{$propertyAddress}-{$mediaType}-{$mediaSize}-{$timestamp}-{$this->downloadJob->uuid}.zip";
                
                $stream = fopen($tmpZip, 'r');
                if ($stream) {
                    Storage::disk('s3')->put($s3Path, $stream, 'private');
                    fclose($stream);
                }
            }

            $options = $this->downloadJob->options ?? [];
            $options['direct_downloads'] = $this->directDownloads;
            $options['zip_filename'] = $zipFileName;

            $this->downloadJob->update([
                'status' => 'completed',
                'zip_path' => $s3Path,
                'expires_at' => now()->addHours(24),
                'options' => $options,
            ]);

        } catch (Throwable $e) {
            $this->downloadJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (file_exists($tmpZip)) {
                @unlink($tmpZip);
            }
            $this->cleanupTempFiles();
        }
    }

    /**
     * Add a file to the ZIP archive, resizing if necessary.
     */
    protected function addFileToZip(ZipArchive $zip, TourFile $file, ImageResizeService $resizer, string $size = 'original', int $index = 0, string $propertyAddress = 'Property', ?string $mlsNumber = null, array $photoSizes = []): void
    {
        $s3Path = $file->file_path;
        if (!Storage::disk('s3')->exists($s3Path)) {
            return;
        }

        $fileSize = Storage::disk('s3')->size($s3Path); // Size in bytes
        $isLargeVideo = ($file->type === 'video' || $file->type === 'video_original') && $fileSize > (200 * 1024 * 1024); // > 200MB

        $origExt = strtolower(pathinfo($s3Path, PATHINFO_EXTENSION) ?: 'jpg');
        // Extension determination
        if ($size === 'mls') {
            $ext = 'jpg';
        } elseif ($size && $size !== 'original' && $file->type === 'photo') {
            $ext = ($origExt === 'png') ? 'png' : 'jpg';
        } else {
            $ext = $origExt ?: 'jpg';
        }

        $pictureNumber = $index + 1;

        // Check if the file has a human-customized tour title (not raw camera prefix or UUID)
        $hasCustomName = false;
        $customName = '';
        if (!empty($file->name)) {
            $rawBase = pathinfo($file->name, PATHINFO_FILENAME);
            $isCameraRaw = preg_match('/^(_?(img|dsc|dji|sam|mov|vid|photo|picture|pic|raw|clip|p)[-_0-9]+|[a-f0-9-]{36}|temp-[0-9]+.*)$/i', $rawBase);
            if (!$isCameraRaw && strlen($rawBase) > 1) {
                $hasCustomName = true;
                $customName = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '', $rawBase));
            }
        }

        // Build file name according to MLS FTP or standard property naming
        if (strtolower($size) === 'mls') {
            // Real Estate Board / Paragon standard: MLS# + Picture number (e.g. C8071918-28.jpg, R3017171-1.jpg)
            if (!empty($mlsNumber)) {
                $fileName = "{$mlsNumber}-{$pictureNumber}.{$ext}";
            } else {
                $fileName = "{$propertyAddress}-{$pictureNumber}.{$ext}";
            }
        } else {
            // Standard downloads (Original, Large, Small): [Address]-[CustomTitle or Index].[ext]
            if ($hasCustomName && !empty($customName)) {
                $fileName = "{$propertyAddress}-{$customName}.{$ext}";
            } else {
                $fileName = "{$propertyAddress}-{$pictureNumber}.{$ext}";
            }
        }

        // If it's a huge video, don't zip it. Provide direct link instead.
        if ($isLargeVideo) {
            $this->directDownloads[] = [
                'uuid' => $file->uuid,
                'name' => $fileName,
                'path' => $s3Path,
                'size' => $fileSize
            ];
            return;
        }

        $tempSource = tempnam(sys_get_temp_dir(), 'bcf_s_');

        // Stream download from S3 to temp file
        $readStream = Storage::disk('s3')->readStream($s3Path);
        $writeStream = fopen($tempSource, 'w');
        stream_copy_to_stream($readStream, $writeStream);
        fclose($writeStream);
        if (is_resource($readStream))
            fclose($readStream);

        // Resize if requested and photo
        if ($size && $size !== 'original' && $file->type === 'photo') {
            $tempResized = tempnam(sys_get_temp_dir(), 'bcf_r_');
            $this->resizeImageFile($tempSource, $tempResized, $size, $photoSizes, $origExt);
            $zip->addFile($tempResized, $fileName);
            $this->tempFiles[] = $tempResized;

            // Delete source immediately as we have the resized version
            @unlink($tempSource);
        } else {
            // Add original to ZIP
            $zip->addFile($tempSource, $fileName);
            $this->tempFiles[] = $tempSource;
        }
    }

    /**
     * Resize image according to organization photo settings and preserve format (JPEG/PNG, never WebP for downloads).
     */
    protected function resizeImageFile(string $sourcePath, string $targetPath, string $size, array $photoSizes = [], string $originalExt = 'jpg'): void
    {
        Log::debug("ProcessMediaDownloadJob: Resizing image file to size: {$size}");
        $image = Image::read($sourcePath);

        // Resolve target dimensions from Org Settings or standards
        $defaultDimensions = [
            'small' => ['width' => 800, 'height' => 533],
            'large' => ['width' => 1920, 'height' => 1280],
            'mls' => ['width' => 2048, 'height' => 1536],
        ];

        $targetWidth = null;
        $targetHeight = null;

        if (isset($photoSizes[$size]['width']) && (int) $photoSizes[$size]['width'] > 0) {
            $targetWidth = (int) $photoSizes[$size]['width'];
        } elseif (isset($defaultDimensions[$size]['width'])) {
            $targetWidth = $defaultDimensions[$size]['width'];
        }

        if (isset($photoSizes[$size]['height']) && (int) $photoSizes[$size]['height'] > 0) {
            $targetHeight = (int) $photoSizes[$size]['height'];
        }

        if ($targetWidth || $targetHeight) {
            $image->scaleDown(width: $targetWidth, height: $targetHeight);
        }

        // Export format: High-Quality JPEG (for MLS and standard photos) or PNG (for PNG graphics)
        if ($size === 'mls' || $originalExt !== 'png') {
            $encoded = (string) $image->toJpeg(92);
        } else {
            $encoded = (string) $image->toPng();
        }

        file_put_contents($targetPath, $encoded);

        // Cleanup memory
        unset($image);
        unset($encoded);
    }

    /**
     * Delete all tracked temporary files.
     */
    protected function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $this->downloadJob->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}
