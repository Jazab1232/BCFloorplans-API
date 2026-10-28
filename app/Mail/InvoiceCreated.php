<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InvoiceCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $invoice;
    public $recipientName;
    public $recipientRole;

    /**
     * Create a new message instance.
     */
    public function __construct(Invoice $invoice, string $recipientName, string $recipientRole = 'agent')
    {
        $this->invoice = $invoice;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Invoice Created: #' . $this->invoice->id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice_created',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        try {
            if ($this->invoice) {
                /** @var InvoicePdfService $pdfService */
                $pdfService = app(InvoicePdfService::class);
                $pdfBytes = $pdfService->getPdfBytes($this->invoice);
                $fileName = $pdfService->getFileName($this->invoice);

                return [
                    Attachment::fromData(fn () => $pdfBytes, $fileName)
                        ->withMime('application/pdf'),
                ];
            }
        } catch (\Throwable $e) {
            Log::error('Failed attaching invoice PDF to InvoiceCreated: ' . $e->getMessage(), [
                'invoice_id' => $this->invoice?->id,
                'trace'      => $e->getTraceAsString(),
            ]);
        }

        return [];
    }
}
