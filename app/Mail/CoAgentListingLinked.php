<?php

namespace App\Mail;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CoAgentListingLinked extends Mailable
{
    use Queueable, SerializesModels;

    public Agent $coAgent;
    public ?Agent $primaryAgent;
    public ?string $propertyAddress;
    public string $portalUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(Agent $coAgent, ?Agent $primaryAgent, ?string $propertyAddress, string $portalUrl)
    {
        $this->coAgent = $coAgent;
        $this->primaryAgent = $primaryAgent;
        $this->propertyAddress = $propertyAddress;
        $this->portalUrl = $portalUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $primaryName = $this->primaryAgent ? ($this->primaryAgent->first_name . ' ' . $this->primaryAgent->last_name) : 'An agent';
        $subject = "You've been added as a co-agent on a listing by {$primaryName}";

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
            view: 'emails.co-agent-listing-linked',
            with: [
                'organization' => $this->coAgent->organization ?? $this->primaryAgent?->organization,
            ],
        );
    }
}
