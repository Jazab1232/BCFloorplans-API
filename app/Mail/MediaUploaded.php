<?php

namespace App\Mail;

use App\Models\Tour;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MediaUploaded extends Mailable
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
        $serviceName = $this->data['service_name'] ?? 'Media';
        $address = $this->data['property_address'] ?? ($this->tour?->orders?->property_address ?? ($this->tour?->order?->property_address ?? 'Property'));
        $isApproved = !empty($this->data['is_approved']);
        $prefix = $isApproved ? 'Media Approved & Ready' : 'New Media Uploaded';

        return new Envelope(
            subject: "{$prefix}: {$serviceName} - {$address}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $order = $this->data['order'] ?? ($this->tour?->orders ?? $this->tour?->order);
        $serviceName = $this->data['service_name'] ?? 'Media';
        $propertyAddress = $this->data['property_address'] ?? ($order?->property_address ?? 'N/A');
        $serviceUrl = $this->data['service_url'] ?? '';
        $mediaCount = $this->data['media_count'] ?? 1;
        $uploadedBy = $this->data['uploaded_by'] ?? 'Vendor';
        $isApproved = !empty($this->data['is_approved']);
        $customMessage = $this->data['custom_message'] ?? null;

        return new Content(
            view: 'emails.media_uploaded',
            with: [
                'recipientName'   => $this->recipientName,
                'recipientRole'   => $this->recipientRole,
                'tour'            => $this->tour,
                'order'           => $order,
                'serviceName'     => $serviceName,
                'propertyAddress' => $propertyAddress,
                'serviceUrl'      => $serviceUrl,
                'mediaCount'      => $mediaCount,
                'uploadedBy'      => $uploadedBy,
                'isApproved'      => $isApproved,
                'customMessage'   => $customMessage,
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
