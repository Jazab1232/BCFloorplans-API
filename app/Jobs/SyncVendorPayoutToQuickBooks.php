<?php

namespace App\Jobs;

use App\Models\VendorInvoice;
use App\Services\QuickBooksService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncVendorPayoutToQuickBooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying (escalating backoff: 1m, 5m, 15m).
     */
    public $backoff = [60, 300, 900];

    protected $vendorInvoiceId;

    /**
     * Create a new job instance.
     */
    public function __construct($vendorInvoiceId)
    {
        $this->vendorInvoiceId = $vendorInvoiceId;
    }

    /**
     * Execute the job.
     */
    public function handle(QuickBooksService $qbService): void
    {
        if (!config('quickbooks.vendor_sync_enabled', true)) {
            Log::info("QuickBooks vendor sync is disabled in configuration. Skipping vendor invoice {$this->vendorInvoiceId}.");
            return;
        }

        $vInvoice = VendorInvoice::with(['vendor.organization', 'organization', 'lines'])->find($this->vendorInvoiceId);

        if (!$vInvoice) {
            Log::error("QuickBooks Vendor Payout Job: Invoice {$this->vendorInvoiceId} not found.");
            return;
        }

        if ($vInvoice->status !== 'paid') {
            Log::info("QuickBooks Vendor Payout Job: Invoice {$vInvoice->id} is not in paid status ({$vInvoice->status}). Skipping.");
            return;
        }

        if ((float) $vInvoice->total_amount <= 0) {
            Log::info("QuickBooks Vendor Payout Job: Invoice {$vInvoice->id} has non-positive amount ({$vInvoice->total_amount}). Skipping.");
            return;
        }

        $org = $vInvoice->vendor?->organization ?? $vInvoice->organization ?? null;
        if (!$qbService->isConnected($org)) {
            Log::info("QuickBooks Vendor Payout Job: Organization " . ($org?->id ?? 'N/A') . " is not connected to QuickBooks. Skipping.");
            return;
        }

        Log::info("QuickBooks Vendor Payout Job: Syncing payout for invoice {$vInvoice->id} to QB");
        $qbService->syncVendorPayoutToQB($vInvoice);
    }
}
