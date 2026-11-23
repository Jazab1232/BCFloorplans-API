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

        // ONE MAIN INVOICE PER ORDER MODEL:
        // If a refund is issued on an individual service invoice, resolve to the Order's Main Invoice
        if ($invoice->order_id && $invoice->notes && stripos($invoice->notes, 'service invoice') !== false) {
            $mainInvoice = Invoice::with(['order.organization', 'agent', 'order.property'])
                ->where('order_id', $invoice->order_id)
                ->where(function ($q) {
                    $q->where('notes', 'ilike', '%consolidated%')
                      ->orWhere('notes', 'ilike', '%add-on service invoice%');
                })
                ->first();

            if (!$mainInvoice) {
                $mainInvoice = Invoice::with(['order.organization', 'agent', 'order.property'])
                    ->where('order_id', $invoice->order_id)
                    ->where('agent_type', 'primary')
                    ->where(function ($q) {
                        $q->whereNull('notes')
                          ->orWhere('notes', 'not ilike', '%service invoice%');
                    })
                    ->first();
            }

            if ($mainInvoice) {
                Log::info("QuickBooks Refund Job: Redirecting service invoice #{$invoice->id} refund to Order Main Invoice #{$mainInvoice->id}");
                $invoice = $mainInvoice;
            }
        }

        if (!$invoice->quickbooks_invoice_id) {
            Log::info("QuickBooks Refund Job: Invoice {$invoice->id} was never synced to QB. Skipping refund sync.");
            return;
        }

        Log::info("QuickBooks Refund Job: Syncing refund of \${$this->amount} for Main Invoice {$invoice->id} to QB");
        $qbService->syncRefundToQB($invoice, $this->amount);
    }
}
