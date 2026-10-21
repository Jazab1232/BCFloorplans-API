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

        // 1. Check for local request origin (preserves localhost:port during local testing)
        $origin = request()?->header('Origin') ?: request()?->header('Referer');
        $isLocalOrigin = false;
        $baseUrl = null;

        if ($origin) {
            $parsedOrigin = parse_url($origin);
            $originHost = strtolower($parsedOrigin['host'] ?? '');
            if ($originHost === 'localhost' || $originHost === '127.0.0.1' || str_ends_with($originHost, '.localhost')) {
                $isLocalOrigin = true;
                $originPort = !empty($parsedOrigin['port']) ? ':' . $parsedOrigin['port'] : '';
                $originScheme = !empty($parsedOrigin['scheme']) ? $parsedOrigin['scheme'] : 'http';
                $baseUrl = "{$originScheme}://{$originHost}{$originPort}";
            }
        }

        // 2. If request was sent directly from a custom whitelabel domain (neither localhost nor platform tojuco.com)
        if (!$isLocalOrigin && !empty($originHost) && !str_contains($originHost, 'tojuco.com')) {
            $originPort = !empty($parsedOrigin['port']) ? ':' . $parsedOrigin['port'] : '';
            $originScheme = !empty($parsedOrigin['scheme']) ? $parsedOrigin['scheme'] : 'https';
            $baseUrl = "{$originScheme}://{$originHost}{$originPort}";
        }

        // 3. If not sent from a custom origin, but the organization is whitelabel in database
        if (!$baseUrl && $org && $org->is_whitelabel) {
            try {
                $domainRecord = $org->domains()->where('portal_type', $this->userType)->first();
                if ($domainRecord && !empty($domainRecord->domain)) {
                    $baseUrl = $domainRecord->domain;
                } elseif (!empty($org->domain)) {
                    $baseUrl = $org->domain;
                }
            } catch (\Throwable $e) {
                if (!empty($org->domain)) {
                    $baseUrl = $org->domain;
                }
            }
        }

            // If not whitelabel (or no custom domain configured), resolve platform domain per role and environment
            if (!$baseUrl) {
                $isDevEnv = (
                    str_contains($originHost ?? '', 'dev.tojuco.com') ||
                    in_array(config('app.env'), ['develop', 'dev', 'development', 'staging', 'local']) ||
                    str_contains(config('app.admin_app', ''), 'dev.tojuco.com')
                );

                if ($isDevEnv) {
                    // 3 Development domains
                    $baseUrl = match ($this->userType) {
                        'agent' => config('app.dev_agent_domain', env('DEFAULT_AGENT_DOMAIN', 'my.dev.tojuco.com')),
                        'vendor' => config('app.dev_vendor_domain', env('DEFAULT_VENDOR_DOMAIN', 'team.dev.tojuco.com')),
                        default => config('app.dev_admin_domain', env('DEFAULT_ADMIN_DOMAIN', 'admin.dev.tojuco.com')),
                    };
                } else {
                    // 3 Production domains
                    $baseUrl = match ($this->userType) {
                        'agent' => config('app.prod_agent_domain', env('DEFAULT_BOOKINGS_DOMAIN', 'bookings.tojuco.com')),
                        'vendor' => config('app.prod_vendor_domain', env('DEFAULT_VENDORS_DOMAIN', 'vendors.tojuco.com')),
                        default => config('app.prod_admin_domain', env('DEFAULT_TEAMS_DOMAIN', 'teams.tojuco.com')),
                    };
                }
            }

        if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
            $baseUrl = (str_contains($baseUrl, 'localhost') || str_contains($baseUrl, '127.0.0.1')) ? 'http://' . $baseUrl : 'https://' . $baseUrl;
        }
        $baseUrl = rtrim($baseUrl, '/');

        // Build reset URL based on user type and org slug
        $orgSlug = $org?->slug ?? null;
        if ($this->userType === 'agent') {
            $resetPath = !empty($orgSlug) ? "/{$orgSlug}/new-password" : '/agent/new-password';
        } elseif ($this->userType === 'vendor') {
            $resetPath = '/vendor/new-password';
        } else {
            $resetPath = '/new-password';
        }

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
