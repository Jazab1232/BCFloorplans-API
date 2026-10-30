<?php

namespace App\Mail;

use App\Models\PrintRequest;
use App\Models\FeatureSheet;
use App\Models\Order;
use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PrintRequestCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public $printRequest;
    public $featureSheet;
    public $order;
    public $agent;
    public $recipientName;

    /**
     * Create a new message instance.
     */
    public function __construct(
        PrintRequest $printRequest,
        ?FeatureSheet $featureSheet = null,
        ?Order $order = null,
        ?Agent $agent = null,
        string $recipientName = 'Agent'
    ) {
        $this->printRequest = $printRequest;
        $this->featureSheet = $featureSheet;
        $this->order = $order;
        $this->agent = $agent;
        $this->recipientName = $recipientName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $address = $this->order?->property_address ?? $this->printRequest->property?->address ?? 'Property';
        $orderId = $this->order?->id ?? $this->featureSheet?->order_id ?? '';

        $orderPrefix = $orderId ? "Order #{$orderId} - " : "";
        return new Envelope(
            subject: "✅ Print Request Completed: {$orderPrefix}{$this->printRequest->copies} Copies ({$address})",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.print_request_completed',
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
