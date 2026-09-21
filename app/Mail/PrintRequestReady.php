<?php

namespace App\Mail;

use App\Models\PrintRequest;
use App\Models\FeatureSheet;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrintRequestReady extends Mailable
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
        $address = $this->order->property_address ?? $this->printRequest->property->address ?? 'Property';
        $orderId = $this->order->id ?? $this->featureSheet->order_id ?? '';

        return new Envelope(
            subject: "🖨️ Print Request Ready: Order #{$orderId} - {$this->printRequest->copies} Copies ({$address})",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.print_request_ready',
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
