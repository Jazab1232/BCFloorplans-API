<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderUpdated extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $recipientName;
    public $recipientRole;
    public $changes;
    public $showInternalNotes;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, string $recipientName, string $recipientRole = 'agent', array $changes = [], bool $showInternalNotes = false)
    {
        $this->order = $order;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
        $this->changes = $changes;
        $this->showInternalNotes = $showInternalNotes;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Order Updated: ' . $this->order->property_address . ' - Order #' . $this->order->id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order_updated',
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
