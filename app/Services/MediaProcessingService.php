<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TourFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;

class MediaProcessingService
{
    /**
     * Resolve the true PHP CLI executable path.
     * Prevents Apache mod_php from incorrectly returning httpd.exe.
     */
    public static function getPhpCliPath(): string
    {
        $binary = PHP_BINARY;
        $lower = strtolower($binary);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $winBinary = str_replace(['php.exe', 'httpd.exe'], 'php-win.exe', $binary);
            if (file_exists($winBinary)) {
                return $winBinary;
            }
            $winCandidates = [
                'C:\laragon\bin\php\php-8.2.0RC1-Win32-vs16-x64\php-win.exe',
                'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php-win.exe',
                'C:\xampp\php\php-win.exe',
            ];
            foreach ($winCandidates as $cand) {
                if (file_exists($cand)) {
                    return $cand;
                }
            }
        }

        // If PHP_BINARY is not httpd.exe / apache, it is already a valid php executable
        if (!str_contains($lower, 'httpd') && !str_contains($lower, 'apache')) {
            return $binary;
        }

        // Try Symfony's PhpExecutableFinder
        $finder = new PhpExecutableFinder();
        $found = $finder->find();
        if ($found && !str_contains(strtolower($found), 'httpd') && !str_contains(strtolower($found), 'apache')) {
            return $found;
        }

        // Known environment candidate locations on Windows
        $candidates = [
            'C:\laragon\bin\php\php-8.2.0RC1-Win32-vs16-x64\php.exe',
            'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe',
            'C:\xampp\php\php.exe',
            'C:\php\php.exe',
        ];

        foreach ($candidates as $cand) {
            if (file_exists($cand)) {
                return $cand;
            }
        }

        return 'php';
    }

    /**
     * Dispatch reprocessing for photos of an order.
     * Immediately marks target files as is_processing=true in the database,
     * and spawns a background CLI process if queue connection is 'sync'.
     *
     * @param Order $order
     * @param bool $force Force reprocessing all matching photos
     * @param array|null $targetFileIds Specific TourFile IDs to reprocess (e.g. for a paid service)
     */
    public static function dispatchOrderReprocessing(Order $order, bool $force = true, ?array $targetFileIds = null): void
    {
        $query = TourFile::where('type', 'photo')
            ->whereHas('tour', function ($q) use ($order) {
                $q->where('order_id', $order->id);
            });

        if (!empty($targetFileIds)) {
            $query->whereIn('id', $targetFileIds);
        }

        $tourFiles = $query->get();
        if ($tourFiles->isEmpty()) {
            Log::info("[MEDIA-PROCESSING] No photos found to reprocess for Order #{$order->id}");
            return;
        }

        // 1. Mark ALL target files as is_processing = true IMMEDIATELY in the database
        // This ensures the frontend immediately sees is_processing=true and renders skeletons
        TourFile::whereIn('id', $tourFiles->pluck('id'))->update(['is_processing' => true]);
        Log::info("[MEDIA-PROCESSING] Marked {$tourFiles->count()} photos as is_processing=true for Order #{$order->id}");

        $queueConnection = config('queue.default');

        // If a real background queue worker is used (database, redis, sqs, etc.)
        if ($queueConnection !== 'sync') {
            foreach ($tourFiles as $tourFile) {
                \App\Jobs\ProcessUploadedImage::dispatch($tourFile)->onQueue('image-processing');
            }
            return;
        }

        // If sync mode, spawn the CLI reprocessor in the background to avoid web timeout
        self::spawnBackgroundReprocessCommand($order, $force, $targetFileIds);
    }

    /**
     * Spawn the media:reprocess-order artisan command in the background
     */
    public static function spawnBackgroundReprocessCommand(Order $order, bool $force = true, ?array $targetFileIds = null): void
    {
        $php = self::getPhpCliPath();
        $artisan = base_path('artisan');
        $args = [
            "'" . addslashes($artisan) . "'",
            "'media:reprocess-order'",
            "'" . addslashes($order->uuid) . "'",
        ];
        if ($force) {
            $args[] = "'--force'";
        }
        if (!empty($targetFileIds)) {
            $args[] = "'--files=" . implode(',', $targetFileIds) . "'";
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows background execution using PowerShell Start-Process detached
            $basePath = base_path();
            $argList = implode(', ', $args);
            $cmd = sprintf(
                'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -Command "Start-Process -FilePath \'%s\' -WorkingDirectory \'%s\' -ArgumentList %s -WindowStyle Hidden"',
                $php,
                $basePath,
                $argList
            );
            pclose(popen($cmd, 'r'));
            Log::info("[MEDIA-BACKGROUND] Spawned Windows CLI background command with php='{$php}': {$cmd}");
        } else {
            // Unix/Linux background execution
            $linuxArgs = [
                escapeshellarg($php),
                escapeshellarg($artisan),
                'media:reprocess-order',
                escapeshellarg($order->uuid),
            ];
            if ($force) {
                $linuxArgs[] = '--force';
            }
            if (!empty($targetFileIds)) {
                $linuxArgs[] = '--files=' . escapeshellarg(implode(',', $targetFileIds));
            }
            $cmd = implode(' ', $linuxArgs) . ' > /dev/null 2>&1 &';
            exec($cmd);
            Log::info("[MEDIA-BACKGROUND] Spawned Linux CLI background command with php='{$php}': {$cmd}");
        }
    }

    /**
     * Self-heal any TourFile records that have been stuck in is_processing = true for > 15 minutes
     */
    public static function autoRecoverStuckFiles(?int $orderId = null): int
    {
        $cutoff = now()->subMinutes(15);
        $query = TourFile::where('is_processing', true)
            ->where('updated_at', '<', $cutoff);

        if ($orderId) {
            $query->whereHas('tour', function ($q) use ($orderId) {
                $q->where('order_id', $orderId);
            });
        }

        $stuckCount = $query->count();
        if ($stuckCount > 0) {
            $query->update(['is_processing' => false]);
            Log::warning("[MEDIA-RECOVERY] Automatically reset {$stuckCount} stuck tour files (updated_at < {$cutoff}) to is_processing=false");
        }

        return $stuckCount;
    }
}
