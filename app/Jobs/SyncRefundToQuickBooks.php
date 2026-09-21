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

class SyncRefundToQuickBooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $invoiceId;
    protected $amount;

    /**
     * Create a new job instance.
     */
    public function __construct($invoiceId, $amount)
    {
        $this->invoiceId = $invoiceId;
        $this->amount = $amount;
    }

    /**
     * Execute the job.
     */
    public function handle(QuickBooksService $qbService): void
    {
        $invoice = Invoice::with(['order.organization', 'agent', 'order.property'])->find($this->invoiceId);

        if (!$invoice) {
            Log::error("QuickBooks Refund Job: Invoice {$this->invoiceId} not found.");
            return;
        }

        if (!$invoice->quickbooks_invoice_id) {
            Log::info("QuickBooks Refund Job: Invoice {$invoice->id} was never synced to QB. Skipping refund sync.");
            return;
        }

        Log::info("QuickBooks Refund Job: Syncing refund for invoice {$invoice->id} to QB");
        $qbService->syncRefundToQB($invoice, $this->amount);
    }
}
