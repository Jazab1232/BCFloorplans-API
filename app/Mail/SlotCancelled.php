<?php

namespace App\Mail;

use App\Models\OrderSlot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SlotCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public $slot;
    public $recipientName;
    public $recipientRole;
    public $cancellationFee;
    public $cancellationReason;

    /**
     * Create a new message instance.
     */
    public function __construct(OrderSlot $slot, string $recipientName, string $recipientRole = 'agent', $cancellationFee = null, ?string $cancellationReason = null)
    {
        $this->slot = $slot;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
        $this->cancellationFee = $cancellationFee;
        $this->cancellationReason = $cancellationReason;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $serviceName = $this->slot->service ? $this->slot->service->name : 'Service';
        $prop = $this->slot->order ? ($this->slot->order->property_address ?? '') : '';
        return new Envelope(
            subject: 'Appointment Cancelled: ' . $serviceName . ($prop ? ' - ' . $prop : ''),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.slot_cancelled',
        );
    }
}
