<?php

namespace App\Mail;

use App\Models\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public $orderService;
    public $recipientName;
    public $recipientRole;
    public $data;

    /**
     * Create a new message instance.
     */
    public function __construct(
        $orderService = null,
        string $recipientName = 'Valued Customer',
        string $recipientRole = 'agent',
        array $data = []
    ) {
        $this->orderService = $orderService;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
        $this->data = $data;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $serviceName = $this->data['service_name'] ?? ($this->orderService?->service?->name ?? 'Service');
        $address = $this->data['property_address'] ?? ($this->orderService?->order?->property_address ?? 'Property');

        return new Envelope(
            subject: "Service Completed: {$serviceName} - {$address}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $order = $this->orderService?->order ?? ($this->data['order'] ?? null);
        $service = $this->orderService?->service ?? ($this->data['service'] ?? null);
        $serviceName = $this->data['service_name'] ?? ($service?->name ?? 'Service');
        $propertyAddress = $this->data['property_address'] ?? ($order?->property_address ?? 'N/A');
        $serviceUrl = $this->data['service_url'] ?? '';

        return new Content(
            view: 'emails.service_completed',
            with: [
                'recipientName'   => $this->recipientName,
                'recipientRole'   => $this->recipientRole,
                'orderService'    => $this->orderService,
                'order'           => $order,
                'service'         => $service,
                'serviceName'     => $serviceName,
                'propertyAddress' => $propertyAddress,
                'serviceUrl'      => $serviceUrl,
                'completedBy'     => $this->data['completed_by'] ?? 'BCFP Team',
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
