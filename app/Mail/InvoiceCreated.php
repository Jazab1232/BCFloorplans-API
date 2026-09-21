<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
}
