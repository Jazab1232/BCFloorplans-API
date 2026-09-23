<?php

namespace App\Mail;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CoAgentInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public Agent $coAgent;
    public ?Agent $primaryAgent;
    public ?string $propertyAddress;
    public string $setupUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(Agent $coAgent, ?Agent $primaryAgent, ?string $propertyAddress, string $setupUrl)
    {
        $this->coAgent = $coAgent;
        $this->primaryAgent = $primaryAgent;
        $this->propertyAddress = $propertyAddress;
        $this->setupUrl = $setupUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $primaryName = $this->primaryAgent ? ($this->primaryAgent->first_name . ' ' . $this->primaryAgent->last_name) : 'An agent';
        $subject = "You've been added as a co-agent by {$primaryName}";

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
            view: 'emails.co-agent-invitation',
            with: [
                'organization' => $this->coAgent->organization ?? $this->primaryAgent?->organization,
            ],
        );
    }
}
