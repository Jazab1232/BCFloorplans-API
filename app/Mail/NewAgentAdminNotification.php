<?php

namespace App\Mail;

use App\Models\Agent;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewAgentAdminNotification extends Mailable
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
        $this->recipientName = 'Admin';
    }

    public function envelope(): Envelope
    {
        $agentName = trim(($this->agent->first_name ?? '') . ' ' . ($this->agent->last_name ?? '')) ?: 'New Agent';
        $orgName = $this->organization?->name ?: 'Tojuco';

        return new Envelope(
            subject: "New Agent Registered: {$agentName} – {$orgName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-agent-admin-notification',
            with: [
                'agent' => $this->agent,
                'organization' => $this->organization,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
