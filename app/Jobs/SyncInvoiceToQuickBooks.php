<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\QbSyncLog;
use App\Services\QuickBooksService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncInvoiceToQuickBooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying (escalating backoff).
     */
    public $backoff = [60, 300, 900];

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
        @set_time_limit(300);
        $invoice = Invoice::with(['order.property', 'items.orderService.service', 'agent', 'order.organization'])
            ->find($this->invoiceId);

        if (!$invoice) {
            Log::error("QuickBooks Sync Job: Invoice {$this->invoiceId} not found.");
            return;
        }

        // ONE MAIN INVOICE PER ORDER MODEL:
        // If this is an individual service invoice (e.g. notes = 'Service Invoice: ...'),
        // resolve the Order's Main (Consolidated) Invoice instead of creating a duplicate QB invoice.
        if ($invoice->order_id && $invoice->notes && stripos($invoice->notes, 'service invoice') !== false) {
            $mainInvoice = Invoice::with(['order.property', 'items.orderService.service', 'agent', 'order.organization'])
                ->where('order_id', $invoice->order_id)
                ->where(function ($q) {
                    $q->where('notes', 'ilike', '%consolidated%')
                      ->orWhere('notes', 'ilike', '%add-on service invoice%');
                })
                ->first();

            // Fallback: look for primary agent invoice not flagged as service invoice
            if (!$mainInvoice) {
                $mainInvoice = Invoice::with(['order.property', 'items.orderService.service', 'agent', 'order.organization'])
                    ->where('order_id', $invoice->order_id)
                    ->where('agent_type', 'primary')
                    ->where(function ($q) {
                        $q->whereNull('notes')
                          ->orWhere('notes', 'not ilike', '%service invoice%');
                    })
                    ->first();
            }

            if ($mainInvoice) {
                Log::info("QuickBooks Sync Job: Redirecting service invoice #{$invoice->id} to Order Main Invoice #{$mainInvoice->id}");
                $invoice = $mainInvoice;
            }
        }

        // Only sync if payment was successful or invoice is marked paid / partially paid
        if ($invoice->paid_amount <= 0 && $invoice->status !== 'paid' && $invoice->status !== 'partially_paid') {
            Log::info("QuickBooks Sync Job: Skipping unpaid invoice {$invoice->id}");
            return;
        }

        Log::info("QuickBooks Sync Job: Syncing Main Invoice {$invoice->id} (Order #{$invoice->order_id}) to QB");
        try {
            $qbService->syncInvoiceToQB($invoice);
        } catch (\Throwable $e) {
            Log::error("QuickBooks Sync Job failed for invoice {$invoice->id}: " . $e->getMessage());

            // Ensure a failed log exists in QbSyncLog so user sees it in the UI
            $existingLog = QbSyncLog::where('entity_type', 'invoice')
                ->where('entity_id', $invoice->id)
                ->latest('id')
                ->first();

            if ($existingLog && $existingLog->status === 'pending') {
                $existingLog->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            } elseif (!$existingLog || $existingLog->status !== 'failed') {
                QbSyncLog::create([
                    'organization_id' => $invoice->organization_id ?? $invoice->order?->organization_id,
                    'entity_type' => 'invoice',
                    'entity_id' => $invoice->id,
                    'action' => $invoice->quickbooks_invoice_id ? 'update' : 'create',
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'request_id' => (string) Str::uuid(),
                ]);
            }
        }
    }
}
