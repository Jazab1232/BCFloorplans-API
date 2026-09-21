<?php

namespace App\Jobs;

use App\Services\QuickBooksService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncVoidInvoiceToQuickBooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $invoiceId;
    protected $qbInvoiceId;

    /**
     * Create a new job instance.
     */
    public function __construct($invoiceId, $qbInvoiceId)
    {
        $this->invoiceId = $invoiceId;
        $this->qbInvoiceId = $qbInvoiceId;
    }

    /**
     * Execute the job.
     */
    public function handle(QuickBooksService $qbService): void
    {
        Log::info("QuickBooks Void Job: Voiding invoice ID {$this->invoiceId} (QB Invoice ID: {$this->qbInvoiceId}) in QB");
        
        try {
            $success = $qbService->voidInvoice($this->qbInvoiceId);
            
            if ($success) {
                Log::info("QuickBooks Void Job: Successfully voided invoice {$this->invoiceId} in QB");
            } else {
                Log::error("QuickBooks Void Job: Failed to void invoice {$this->invoiceId} in QB");
            }
        } catch (\Throwable $e) {
            Log::error("QuickBooks Void Job: Exception voiding invoice {$this->invoiceId} in QB: " . $e->getMessage());
        }
    }
}
