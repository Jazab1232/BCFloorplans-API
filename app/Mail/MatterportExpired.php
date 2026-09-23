<?php

namespace App\Mail;

use App\Models\Tour;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MatterportExpired extends Mailable
{
    use Queueable, SerializesModels;

    public $tour;
    public $recipientName;
    public $recipientRole;
    public $data;

    /**
     * Create a new message instance.
     */
    public function __construct(
        $tour = null,
        string $recipientName = 'Valued Customer',
        string $recipientRole = 'agent',
        array $data = []
    ) {
        $this->tour = $tour;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
        $this->data = $data;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $address = $this->data['propertyAddress'] ?? $this->data['property_address'] ?? ($this->tour?->orders?->property_address ?? 'Property');

        return new Envelope(
            subject: "Notice: Matterport 3D Tour Hosting Expired - {$address}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.matterport_expired',
            with: [
                'recipientName'   => $this->recipientName,
                'recipientRole'   => $this->recipientRole,
                'propertyAddress' => $this->data['propertyAddress'] ?? $this->data['property_address'] ?? ($this->tour?->orders?->property_address ?? 'N/A'),
                'expiryDate'      => $this->data['expiryDate'] ?? $this->data['expiry_date'] ?? 'Today',
                'agentName'       => $this->data['agentName'] ?? $this->data['agent_name'] ?? '',
                'renewalUrl'      => $this->data['renewalUrl'] ?? $this->data['renewal_url'] ?? '',
                'tour'            => $this->tour,
                'data'            => $this->data,
            ],
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
