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
        $org = $notifiable->organization ?? null;
        if (!$org && isset($notifiable->organization_id)) {
            $org = \App\Models\Organization::find($notifiable->organization_id);
        }

        $baseUrl = config('app.frontend_url', config('app.admin_app', 'https://teams.tojuco.com'));

        // Resolve dynamic whitelabel domain if user belongs to a whitelabel organization
        if ($org && $org->is_whitelabel) {
            $domainRecord = $org->domains()->where('portal_type', $this->userType)->first();
            if ($domainRecord && !empty($domainRecord->domain)) {
                $baseUrl = $domainRecord->domain;
            } elseif (!empty($org->domain)) {
                $baseUrl = $org->domain;
            }
        }

        if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://' . $baseUrl;
        }
        $baseUrl = rtrim($baseUrl, '/');

        // Build reset URL based on user type
        $resetPath = match ($this->userType) {
            'agent' => '/agent/new-password',
            'vendor' => '/vendor/new-password',
            default => '/new-password', // admin
        };

        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : ($notifiable->email ?? '');

        $resetUrl = $baseUrl . $resetPath . '?token=' . urlencode($this->token) . '&email=' . urlencode($email) . '&role=' . urlencode($this->userType);

        // Get user name (handle different field names)
        $name = $notifiable->name ?? $notifiable->first_name ?? 'User';

        $orgName = $org?->name ?: 'Tojuco';

        return (new MailMessage)
            ->subject("Reset Password for {$orgName}")
            ->view('emails.reset-password', [
                'url' => $resetUrl,
                'name' => $name,
                'organization' => $org,
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
