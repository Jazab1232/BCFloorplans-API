<?php

namespace App\Mail;

use App\Models\OrderSlot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SlotBooked extends Mailable
{
    use Queueable, SerializesModels;

    public $slot;
    public $recipientName;
    public $recipientRole;

    /**
     * Create a new message instance.
     */
    public function __construct(OrderSlot $slot, string $recipientName, string $recipientRole = 'agent')
    {
        $this->slot = $slot;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $serviceName = $this->slot->service ? $this->slot->service->name : 'Service';
        return new Envelope(
            subject: 'Appointment Scheduled: ' . $serviceName . ' - ' . ($this->slot->order->property_address ?? ''),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.slot_booked',
        );
    }
}
