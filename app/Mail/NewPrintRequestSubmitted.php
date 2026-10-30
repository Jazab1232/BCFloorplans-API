<?php

namespace App\Mail;

use App\Models\PrintRequest;
use App\Models\FeatureSheet;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewPrintRequestSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public $printRequest;
    public $featureSheet;
    public $order;
    public $recipientName;

    /**
     * Create a new message instance.
     */
    public function __construct(PrintRequest $printRequest, FeatureSheet $featureSheet, Order $order = null, string $recipientName = 'Admin')
    {
        $this->printRequest = $printRequest;
        $this->featureSheet = $featureSheet;
        $this->order = $order;
        $this->recipientName = $recipientName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $address = $this->order->property_address ?? $this->printRequest->property?->address ?? 'Property';
        $orderId = $this->order->id ?? $this->featureSheet->order_id ?? '';

        return new Envelope(
            subject: "🖨️ New Print Request: Order #{$orderId} - {$this->printRequest->copies} Copies ({$address})",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.new_print_request_submitted',
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
