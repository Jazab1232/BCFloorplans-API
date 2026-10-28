<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\TourFile;
use App\Jobs\ProcessUploadedImage;
use App\Services\ImageResizeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReprocessOrderMedia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:reprocess-order 
                            {order : The UUID or ID of the order}
                            {--force : Force reprocessing of all photos even if completed}
                            {--only-stuck : Only process photos that currently have is_processing = true}
                            {--limit= : Limit the number of photos to process}
                            {--files= : Comma-separated list of TourFile IDs to reprocess}
                            {--service= : Only process photos belonging to a specific service ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reliably reprocess variants and watermarks for all photos in an order in the background';

    /**
     * Execute the console command.
     */
    public function handle(ImageResizeService $resizer)
    {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        @ignore_user_abort(true);

        $orderIdentifier = $this->argument('order');
        $force = (bool) $this->option('force');
        $onlyStuck = (bool) $this->option('only-stuck');
        $limit = $this->option('limit');
        $filesOption = $this->option('files');
        $serviceOption = $this->option('service');

        $order = Order::withoutGlobalScopes()
            ->where('uuid', $orderIdentifier)
            ->orWhere('id', is_numeric($orderIdentifier) ? (int) $orderIdentifier : 0)
            ->first();

        if (!$order) {
            $this->error("Order not found: {$orderIdentifier}");
            Log::error("[REPROCESS-ORDER] Order not found: {$orderIdentifier}");
            return 1;
        }

        $this->info("Scanning photos for Order #{$order->id} ({$order->uuid})...");
        Log::info("[REPROCESS-ORDER] Starting order media reprocessing for Order #{$order->id} ({$order->uuid})");

        $query = TourFile::where('type', 'photo')
            ->whereHas('tour', function ($q) use ($order) {
                $q->where('order_id', $order->id);
            });

        if ($onlyStuck) {
            $query->where('is_processing', true);
        }

        if (!empty($filesOption)) {
            $ids = array_filter(array_map('intval', explode(',', $filesOption)));
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        if (!empty($serviceOption)) {
            $query->where('service_id', (int) $serviceOption);
        }

        if ($limit && is_numeric($limit)) {
            $query->limit((int) $limit);
        }

        $files = $query->orderBy('id', 'asc')->get();
        $total = $files->count();

        if ($total === 0) {
            $this->info("No eligible photos found to reprocess.");
            Log::info("[REPROCESS-ORDER] No eligible photos found for Order #{$order->id}");
            return 0;
        }

        $this->info("Found {$total} photo(s) to process. Starting sequential processing...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $failed = 0;

        foreach ($files as $index => $file) {
            $currentNum = $index + 1;
            try {
                // Ensure is_processing is true while being worked on
                $file->update(['is_processing' => true]);

                $job = new ProcessUploadedImage($file);
                $job->handle($resizer);

                $processed++;
                Log::info("[REPROCESS-ORDER] Successfully processed ({$currentNum}/{$total}) file id={$file->id} uuid={$file->uuid}");
            } catch (Throwable $e) {
                $failed++;
                Log::error("[REPROCESS-ORDER] Failed ({$currentNum}/{$total}) file id={$file->id} uuid={$file->uuid}: " . $e->getMessage(), [
                    'exception' => $e,
                ]);

                // Ensure file is NEVER left permanently stuck in is_processing = true
                try {
                    $file->update(['is_processing' => false]);
                } catch (Throwable $ignored) {
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Reprocessing complete for Order #{$order->id}: {$processed} processed, {$failed} failed out of {$total}.");
        Log::info("[REPROCESS-ORDER] Finished order media reprocessing for Order #{$order->id}: {$processed} processed, {$failed} failed out of {$total}.");

        return 0;
    }
}
