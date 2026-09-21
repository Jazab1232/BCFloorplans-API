<?php

namespace App\Mail;

use App\Models\OrderSlot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SlotRescheduled extends Mailable
{
    use Queueable, SerializesModels;

    public $slot;
    public $recipientName;
    public $recipientRole;
    public $oldDate;
    public $oldStartTime;

    /**
     * Create a new message instance.
     */
    public function __construct(OrderSlot $slot, string $recipientName, string $recipientRole = 'agent', ?string $oldDate = null, ?string $oldStartTime = null)
    {
        $this->slot = $slot;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
        $this->oldDate = $oldDate;
        $this->oldStartTime = $oldStartTime;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $serviceName = $this->slot->service ? $this->slot->service->name : 'Service';
        return new Envelope(
            subject: 'Appointment Rescheduled: ' . $serviceName . ' - ' . ($this->slot->order->property_address ?? ''),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.slot_rescheduled',
        );
    }
}
