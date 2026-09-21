<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $recipientName;
    public $recipientRole;
    public $cancellationFee;
    public $cancellationReason;
    public $isForAdmin;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, string $recipientName, string $recipientRole = 'agent', ?float $cancellationFee = null, ?string $cancellationReason = null, bool $isForAdmin = false)
    {
        $this->order = $order;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
        $this->cancellationFee = $cancellationFee;
        $this->cancellationReason = $cancellationReason;
        $this->isForAdmin = $isForAdmin;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->isForAdmin
            ? "ADMIN ALERT: Order #{$this->order->id} Cancelled - {$this->order->property_address}"
            : "Order #{$this->order->id} Cancelled - {$this->order->property_address}";

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order_cancelled',
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
