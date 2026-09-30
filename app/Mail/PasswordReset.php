<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public string $url;
    public string $recipientName;
    public string $userType;
    public ?Organization $organization;

    /**
     * Create a new message instance.
     *
     * @param string            $url           The full password reset URL
     * @param string            $recipientName The user display name
     * @param string            $userType      admin, agent, or vendor
     * @param Organization|null $organization  Org context for whitelabel branding
     */
    public function __construct(
        string $url,
        string $recipientName,
        string $userType = 'admin',
        ?Organization $organization = null
    ) {
        $this->url           = $url;
        $this->recipientName = $recipientName;
        $this->userType      = $userType;
        $this->organization  = $organization;
    }

    public function envelope(): Envelope
    {
        $org     = $this->organization;
        $orgName = ($org && $org->is_whitelabel) ? $org->name : 'Tojuco';

        return new Envelope(
            subject: "Reset Your Password – {$orgName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
            with: [
                'url'          => $this->url,
                'name'         => $this->recipientName,
                'userType'     => $this->userType,
                'organization' => $this->organization,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
