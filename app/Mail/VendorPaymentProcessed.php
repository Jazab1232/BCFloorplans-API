<?php

namespace App\Mail;

use App\Models\VendorPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class VendorPaymentProcessed extends Mailable
{
    use Queueable, SerializesModels;

    public $payment;
    public $services;
    public $recipientName;

    /**
     * Create a new message instance.
     */
    public function __construct(VendorPayment $payment, Collection $services, string $recipientName = 'Vendor')
    {
        $this->payment = $payment;
        $this->services = $services;
        $this->recipientName = $recipientName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment: Transferred Successfully - $' . number_format($this->payment->amount, 2),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.vendor_payment_processed',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
