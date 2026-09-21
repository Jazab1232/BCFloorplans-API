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
        $vInvoice = VendorInvoice::with(['vendor'])->find($this->vendorInvoiceId);

        if (!$vInvoice) {
            Log::error("QuickBooks Vendor Payout Job: Invoice {$this->vendorInvoiceId} not found.");
            return;
        }

        Log::info("QuickBooks Vendor Payout Job: Syncing payout for invoice {$vInvoice->id} to QB");
        $qbService->syncVendorPayoutToQB($vInvoice);
    }
}
