<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;
    public $token;
    public $userType;

    /**
     * Create a new notification instance.
     * 
     * @param string $token
     * @param string $userType (admin, agent, vendor)
     */
    public function __construct($token, $userType = 'admin')
    {
        $this->token = $token;
        $this->userType = $userType;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $adminAppUrl = config('app.admin_app');
        
        // Resolve dynamic whitelabel domain if user belongs to a whitelabel organization
        $org = $notifiable->organization ?? null;
        if ($org && $org->is_whitelabel) {
            // Find custom subdomain mapped to this portal type
            $domainRecord = $org->domains()->where('portal_type', $this->userType)->first();
            if ($domainRecord) {
                $adminAppUrl = 'https://' . $domainRecord->domain . '/';
            } else {
                $adminAppUrl = 'https://' . ($org->domain ?? 'tojuco.com') . '/';
            }
        }

        // Build reset URL based on user type
        $resetPath = match($this->userType) {
            'agent' => 'agent/new-password',
            'vendor' => 'vendor/new-password',
            default => 'new-password', // admin
        };
        
        if (substr($adminAppUrl, -1) !== '/') {
            $adminAppUrl .= '/';
        }
        
        $resetUrl = $adminAppUrl . $resetPath . '?token=' . $this->token . '&email=' . urlencode($notifiable->email) . '&role=' . $this->userType;

        // Get user name (handle different field names)
        $name = $notifiable->name ?? $notifiable->first_name ?? 'User';

        $orgName = ($org && $org->is_whitelabel) ? $org->name : 'Tojuco';

        return (new MailMessage)
            ->subject("Reset Password for {$orgName} Platform")
            ->view('emails.reset-password', [
                'url' => $resetUrl,
                'name' => $name,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
