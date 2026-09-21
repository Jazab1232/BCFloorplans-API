<?php

namespace App\Services\MediaSync;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use App\Models\TourFile;
use App\Models\Tour;
use Illuminate\Support\Facades\Log;
use App\Services\ImageResizeService;
use Throwable;
use Intervention\Image\Laravel\Facades\Image;

class FtpSyncService
{
    protected string $diskName = 'mls_ftp';
    protected int $mlsMaxWidth;
    protected int $mlsQuality;

    public function __construct(
        protected ImageResizeService $imageResizeService
    ) {
        $this->mlsMaxWidth = (int) env('MLS_SYNC_MAX_WIDTH', 2048);
        $this->mlsQuality = (int) env('MLS_SYNC_QUALITY', 90);
    }

    /**
     * Test FTP/SFTP connection.
     */
    public function testConnection(): bool
    {
        Log::info('[FTP] Testing connection');

        try {
            $disk = Storage::disk($this->diskName);

            $files = $disk->files('.');
            Log::info('[FTP] Connection OK', [
                'file_count' => count($files),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::critical('[FTP] Connection FAILED', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Sync a single file to FTP/SFTP.
     */
    /**
     * Sync a single file to FTP/SFTP with Paragon naming convention.
     */
    public function syncFile(TourFile $file, string $mlsNumber, int $index): bool
    {
        Log::info('[FTP] Sync started', [
            'tour_file_id' => $file->id,
            'mls_number' => $mlsNumber,
            'index' => $index,
            'db_path' => $file->file_path,
        ]);

        /** ----------------------------
         * 1️⃣ S3 file validation
         * ---------------------------- */
        if (!Storage::disk('s3')->exists($file->file_path)) {
            Log::error('[FTP] S3 file NOT FOUND', ['path' => $file->file_path]);
            return false;
        }

        /** ----------------------------
         * 2️⃣ Resolve FTP disk
         * ---------------------------- */
        try {
            $ftp = Storage::disk($this->diskName);
        } catch (Throwable $e) {
            Log::critical('[FTP] FTP disk resolution FAILED', ['error' => $e->getMessage()]);
            return false;
        }

        /** ----------------------------
         * 3️⃣ Build remote path (Paragon: MLS#-Index.jpg)
         * ---------------------------- */
        $extension = pathinfo($file->file_path, PATHINFO_EXTENSION) ?: 'jpg';
        $remotePath = "{$mlsNumber}-{$index}.{$extension}";

        /** ----------------------------
         * 4️⃣ Prepare Content (with Paragon Resolution limits)
         * ---------------------------- */
        $uploadStream = null;
        $tempResized = null;
        $maxMlsW = 3072; // Paragon Max
        $maxMlsH = 2304; // Paragon Max

        try {
            if ($file->type === 'photo') {
                Log::info('[FTP] Processing image for Paragon limits', ['max' => "{$maxMlsW}x{$maxMlsH}"]);

                $tempOriginal = tempnam(sys_get_temp_dir(), 's3_orig_');
                $s3Stream = Storage::disk('s3')->readStream($file->file_path);
                file_put_contents($tempOriginal, $s3Stream);
                if (is_resource($s3Stream)) fclose($s3Stream);

                $img = Image::read($tempOriginal);
                
                // Enforce Paragon dimensions
                if ($img->width() > $maxMlsW || $img->height() > $maxMlsH) {
                    $img->scaleDown(width: $maxMlsW, height: $maxMlsH);
                }

                $tempResized = tempnam(sys_get_temp_dir(), 'mls_upload_');
                $img->toJpeg($this->mlsQuality)->save($tempResized);
                
                @unlink($tempOriginal);
                $uploadStream = fopen($tempResized, 'r');
            } else {
                $uploadStream = Storage::disk('s3')->readStream($file->file_path);
            }

            $ftp->put($remotePath, $uploadStream);
            Log::info('[FTP] Upload SUCCESS', ['remote_path' => $remotePath]);
            return true;

        } catch (Throwable $e) {
            Log::error('[FTP] Upload FAILED', [
                'remote_path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        } finally {
            if (is_resource($uploadStream)) fclose($uploadStream);
            if ($tempResized && file_exists($tempResized)) @unlink($tempResized);
        }
    }

    /**
     * Sync Virtual Tour links as a .txt file.
     */
    public function syncVirtualTour(Tour $tour, string $mlsNumber): bool
    {
        Log::info('[FTP] Syncing Virtual Tour .txt', ['tour_id' => $tour->id, 'mls' => $mlsNumber]);

        try {
            $links = $tour->links()->where('is_publish', true)->get();
            if ($links->isEmpty()) {
                Log::warning('[FTP] No published links found for tour', ['tour_id' => $tour->id]);
                // return true; // Nothing to sync, but not a failure
            }

            // Create content: one URL per line
            $content = "";
            foreach ($links as $link) {
                $content .= $link->link . PHP_EOL;
            }

            $remotePath = "{$mlsNumber}.txt";
            $ftp = Storage::disk($this->diskName);
            $ftp->put($remotePath, $content);

            Log::info('[FTP] Virtual Tour .txt Upload SUCCESS', ['remote_path' => $remotePath]);
            return true;
        } catch (Throwable $e) {
            Log::error('[FTP] Virtual Tour .txt Upload FAILED', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Resolve MLS Number for a Tour.
     */
    public function getMlsNumber(Tour $tour): ?string
    {
        $mls = $tour->orders?->property?->mls_number ?: $tour->orders?->property?->mls_property;
        
        if (!$mls) {
            Log::warning('[FTP] MLS Number missing for tour', ['tour_id' => $tour->id]);
            return null;
        }

        // Clean name (Paragon/FTP standard: No spaces or special chars)
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $mls);
        return !empty($clean) ? $clean : null;
    }

    /**
     * Sync multiple files with Paragon naming.
     */
    public function syncFiles(Collection $files, string $mlsNumber): array
    {
        Log::info('[FTP] Batch sync started', [
            'mls_number' => $mlsNumber,
            'count' => $files->count(),
        ]);

        $results = [
            'success' => [],
            'failed' => [],
        ];

        $index = 1;
        foreach ($files as $file) {
            if ($this->syncFile($file, $mlsNumber, $index)) {
                $results['success'][] = $file->id;
                $index++;
            } else {
                $results['failed'][] = $file->id;
            }
        }

        Log::info('[FTP] Batch sync completed', $results);

        return $results;
    }
}
