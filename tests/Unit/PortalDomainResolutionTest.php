<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Service;
use App\Services\EmailDispatchService;
use ReflectionMethod;
use Tests\TestCase;

class PortalDomainResolutionTest extends TestCase
{
    public function test_non_whitelabel_resolves_agent_domain_from_env_or_config(): void
    {
        config()->set('app.agent_url', 'https://my.customagentdomain.com');
        config()->set('app.admin_app', 'https://admin.dev.tojuco.com');

        $service = new EmailDispatchService();

        $agentUrl = $service->resolvePortalBaseUrl('agent', null);
        $adminUrl = $service->resolvePortalBaseUrl('admin', null);

        $this->assertSame('https://my.customagentdomain.com', $agentUrl);
        $this->assertSame('https://admin.dev.tojuco.com', $adminUrl);
    }

    public function test_whitelabel_resolves_role_specific_domain(): void
    {
        $service = new EmailDispatchService();

        $org = new Organization();
        $org->id = 999;
        $org->name = 'Realty Corp';
        $org->is_whitelabel = true;
        $org->domain = 'realtycorp.com';

        // When no domain records exist, falls back to $org->domain
        $resolvedAgent = $service->resolvePortalBaseUrl('agent', $org);
        $resolvedAdmin = $service->resolvePortalBaseUrl('admin', $org);

        $this->assertSame('https://realtycorp.com', $resolvedAgent);
        $this->assertSame('https://realtycorp.com', $resolvedAdmin);
    }

    public function test_service_url_generates_correct_agent_and_admin_urls(): void
    {
        config()->set('app.agent_url', 'https://bookings.tojuco.com');
        config()->set('app.admin_app', 'https://teams.tojuco.com');

        $service = new EmailDispatchService();

        $order = new Order();
        $order->uuid = 'ord-123-uuid';
        $order->id = 101;
        $order->setRelation('services', collect());
        $order->setRelation('slots', collect());
        $order->setRelation('tours', collect());
        $order->setRelation('property', null);
        $order->setRelation('agent', null);

        $bookedService = new Service();
        $bookedService->uuid = 'svc-456-uuid';
        $bookedService->name = 'HDR Photography';

        $method = new ReflectionMethod(EmailDispatchService::class, 'buildRoleData');
        $method->setAccessible(true);

        // Agent recipient
        $agentRecipient = [
            'email' => 'agent@example.com',
            'name' => 'Agent John',
            'role' => 'agent',
        ];
        $agentData = $method->invoke($service, 'media_uploaded', $order, $agentRecipient, null, ['service' => $bookedService]);
        $this->assertStringStartsWith('https://bookings.tojuco.com/dashboard/file-manager/ord-123-uuid', $agentData['service_url']);
        $this->assertStringContainsString('serviceId=svc-456-uuid', $agentData['service_url']);

        // Admin recipient
        $adminRecipient = [
            'email' => 'admin@example.com',
            'name' => 'Admin Boss',
            'role' => 'admin',
        ];
        $adminData = $method->invoke($service, 'media_uploaded', $order, $adminRecipient, null, ['service' => $bookedService]);
        $this->assertStringStartsWith('https://teams.tojuco.com/dashboard/file-manager/ord-123-uuid', $adminData['service_url']);
    }

    public function test_service_url_generates_whitelabel_domain_url(): void
    {
        $service = new EmailDispatchService();

        $org = new Organization();
        $org->id = 777;
        $org->is_whitelabel = true;
        $org->domain = 'custom-agency.com';

        $order = new Order();
        $order->uuid = 'ord-wl-uuid';
        $order->id = 202;
        $order->setRelation('services', collect());
        $order->setRelation('slots', collect());
        $order->setRelation('tours', collect());
        $order->setRelation('property', null);
        $order->setRelation('agent', null);

        $method = new ReflectionMethod(EmailDispatchService::class, 'buildRoleData');
        $method->setAccessible(true);

        $agentRecipient = [
            'email' => 'agent@custom-agency.com',
            'name' => 'Agent Custom',
            'role' => 'agent',
        ];

        $data = $method->invoke($service, 'media_uploaded', $order, $agentRecipient, $org);
        $this->assertStringStartsWith('https://custom-agency.com/dashboard/file-manager/ord-wl-uuid', $data['service_url']);
    }
}
