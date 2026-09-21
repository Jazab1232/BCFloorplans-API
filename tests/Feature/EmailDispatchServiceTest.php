<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Order;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\NotificationPreference;
use App\Models\EmailLog;
use App\Services\EmailDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $organization;
    protected $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Whitelabel Org',
            'slug' => 'test-wl-org',
            'contact_email' => 'admin@test.com',
            'contact_phone' => '123-456-7890',
            'is_whitelabel' => true,
        ]);

        $this->agent = Agent::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'John',
            'last_name' => 'Agent',
            'email' => 'agent@test.com',
            'password' => bcrypt('password'),
            'status' => true
        ]);
    }

    public function test_email_dispatch_service_sends_to_configured_recipients()
    {
        Mail::fake();

        $order = Order::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'agent_id' => $this->agent->id,
            'amount' => 500.00,
            'property_address' => '123 Test St',
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);

        $service = new EmailDispatchService();
        $service->dispatch('order_created', $order);

        Mail::assertSent(\App\Mail\OrderCreated::class, function ($mail) {
            return $mail->hasTo('admin@test.com') || $mail->hasTo('agent@test.com');
        });

        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'admin@test.com',
            'status' => 'sent',
        ]);
    }

    public function test_email_dispatch_respects_notification_preferences()
    {
        Mail::fake();

        $order = Order::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'agent_id' => $this->agent->id,
            'amount' => 500.00,
            'property_address' => '123 Test St',
        ]);

        // Disable order_created for agent
        NotificationPreference::create([
            'organization_id' => $this->organization->id,
            'role' => 'agent',
            'event_type' => 'order_created',
            'email_enabled' => false,
        ]);

        $service = new EmailDispatchService();
        $service->dispatch('order_created', $order);

        // Assert mail was sent only to admin, not agent
        Mail::assertSent(\App\Mail\OrderCreated::class, function ($mail) {
            return $mail->hasTo('admin@test.com');
        });
        Mail::assertNotSent(\App\Mail\OrderCreated::class, function ($mail) {
            return $mail->hasTo('agent@test.com');
        });
    }
}
