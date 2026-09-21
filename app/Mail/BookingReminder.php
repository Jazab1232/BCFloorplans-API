<?php

namespace App\Mail;

use App\Models\OrderSlot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingReminder extends Mailable
{
    use Queueable, SerializesModels;

    public $orderSlot;
    public $recipientRole;

    /**
     * Create a new message instance.
     */
    public function __construct(OrderSlot $orderSlot, string $recipientRole = 'vendor')
    {
        $this->orderSlot = $orderSlot;
        $this->recipientRole = $recipientRole;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $address = $this->orderSlot->order ? $this->orderSlot->order->property_address : '';
        return new Envelope(
            subject: 'Reminder: You have an appointment coming up - ' . ($address ?: 'New Service'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.booking_reminder',
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
