<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\QuickBooksService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncInvoiceToQuickBooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $invoiceId;

    /**
     * Create a new job instance.
     */
    public function __construct($invoiceId)
    {
        $this->invoiceId = $invoiceId;
    }

    /**
     * Execute the job.
     */
    public function handle(QuickBooksService $qbService): void
    {
        $invoice = Invoice::with(['order.property', 'items.orderService.service', 'agent', 'order.organization'])
            ->find($this->invoiceId);

        if (!$invoice) {
            Log::error("QuickBooks Sync Job: Invoice {$this->invoiceId} not found.");
            return;
        }

        // Only sync if payment was successful (per client feedback)
        // or if it's explicitly triggered for a partial payment
        if ($invoice->paid_amount <= 0 && $invoice->status !== 'paid') {
            Log::info("QuickBooks Sync Job: Skipping unpaid invoice {$invoice->id}");
            return;
        }

        Log::info("QuickBooks Sync Job: Syncing invoice {$invoice->id} to QB");
        $qbService->syncInvoiceToQB($invoice);
    }
}
