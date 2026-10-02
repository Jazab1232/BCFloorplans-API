<?php

namespace Tests\Unit;

use App\Mail\DynamicMailable;
use App\Models\Organization;
use App\Services\EmailDispatchService;
use ReflectionMethod;
use Tests\TestCase;

class EmailSenderResolutionTest extends TestCase
{
    public function test_non_whitelabel_sender_ignores_legacy_mail_from_address(): void
    {
        config()->set('mail.from.address', 'noreply@bcfloorplans.com');
        config()->set('services.resend.from_address', 'noreply@tujoco.com');
        config()->set('services.resend.from_name', 'Tojuco Solutions');

        $organization = new Organization();
        $organization->name = 'Customer Organization';
        $organization->is_whitelabel = false;
        $mailable = $this->setSender($organization);

        $this->assertSame('noreply@tujoco.com', $mailable->from[0]['address']);
        $this->assertSame('Customer Organization (via Tojuco Solutions)', $mailable->from[0]['name']);
    }

    public function test_whitelabel_sender_uses_organization_address(): void
    {
        config()->set('services.resend.from_address', 'noreply@tujoco.com');
        config()->set('services.resend.from_name', 'Tojuco Solutions');

        $organization = new Organization();
        $organization->name = 'White Label Organization';
        $organization->is_whitelabel = true;
        $organization->from_email = 'billing@whitelabel.example';
        $organization->from_name = 'White Label Billing';
        $mailable = $this->setSender($organization);

        $this->assertSame('billing@whitelabel.example', $mailable->from[0]['address']);
        $this->assertSame('White Label Billing', $mailable->from[0]['name']);
    }

    private function setSender(Organization $organization): DynamicMailable
    {
        $mailable = new DynamicMailable('<p>Test</p>', 'Test email', $organization, 'admin');
        $method = new ReflectionMethod(EmailDispatchService::class, 'setFromAddress');
        $method->setAccessible(true);

        return $method->invoke(new EmailDispatchService(), $mailable, $organization);
    }
}
