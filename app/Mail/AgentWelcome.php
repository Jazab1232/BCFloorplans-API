<?php

namespace App\Mail;

use App\Models\Agent;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentWelcome extends Mailable
{
    use Queueable, SerializesModels;

    public Agent $agent;
    public ?Organization $organization;
    public string $loginUrl;
    public string $recipientName;

    /**
     * Create a new message instance.
     */
    public function __construct(
        Agent $agent,
        ?Organization $organization = null,
        string $loginUrl = ''
    ) {
        $this->agent = $agent;
        $this->organization = $organization;
        $this->loginUrl = $loginUrl;
        $this->recipientName = trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')) ?: 'Agent';
    }

    public function envelope(): Envelope
    {
        $orgName = $this->organization?->name ?: 'Tojuco';

        return new Envelope(
            subject: "Welcome to {$orgName} – Your Agent Account is Ready",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.agent-welcome',
            with: [
                'agent' => $this->agent,
                'organization' => $this->organization,
                'loginUrl' => $this->loginUrl,
                'recipientName' => $this->recipientName,
            ],
        );
    }
}
