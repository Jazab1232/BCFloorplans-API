<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Organization;

class DynamicMailable extends Mailable
{
    use Queueable, SerializesModels;

    public $content;
    public $subjectText;
    public $organization;
    public $recipientRole;

    /**
     * Create a new message instance.
     */
    public function __construct(string $content, string $subjectText, ?Organization $org, string $recipientRole)
    {
        $this->content = $content;
        $this->subjectText = $subjectText;
        $this->organization = $org;
        $this->recipientRole = $recipientRole;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectText,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.dynamic',
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
