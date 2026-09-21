<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Notification as NotificationModel;
use App\Models\EmailLog;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $organization;
    protected $admin1;
    protected $admin2;
    protected $inactiveAdmin;
    protected $noEmailAdmin;
    protected $vendor;
    protected $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Acme Media Org',
            'slug' => 'acme-media',
            'contact_email' => 'contact@acme.com',
            'from_email' => 'no-reply@acme.com',
            'is_whitelabel' => true,
        ]);

        // Active Admin 1
        $this->admin1 = User::create([
            'first_name' => 'Alice',
            'last_name' => 'Admin',
            'email' => 'alice@acme.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'notification_email' => true,
            'account_closed' => false,
        ]);

        // Active Admin 2
        $this->admin2 = User::create([
            'first_name' => 'Bob',
            'last_name' => 'Admin',
            'email' => 'bob@acme.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'notification_email' => true,
            'account_closed' => false,
        ]);

        // Closed Account Admin (should NOT receive email)
        $this->inactiveAdmin = User::create([
            'first_name' => 'Charlie',
            'last_name' => 'Closed',
            'email' => 'charlie@acme.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'notification_email' => true,
            'account_closed' => true,
        ]);

        // Admin who opted out of notification emails (should NOT receive email)
        $this->noEmailAdmin = User::create([
            'first_name' => 'David',
            'last_name' => 'OptOut',
            'email' => 'david@acme.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'notification_email' => false,
            'account_closed' => false,
        ]);

        // Vendor
        $this->vendor = Vendor::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Victor',
            'last_name' => 'Vendor',
            'email' => 'vendor@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'review_files' => true,
        ]);

        // Order
        $this->order = Order::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'amount' => 350.00,
            'property_address' => '742 Evergreen Terrace',
            'property_location' => 'Springfield',
            'order_status' => 'In Progress',
            'payment_status' => 'PAID',
        ]);
    }

    public function test_send_email_fans_out_to_all_active_admins_when_to_is_placeholder()
    {
        Notification::fake();

        $response = $this->actingAs($this->vendor, 'vendor-api')->postJson('/api/notifications/email', [
            'to' => 'info@bcfplatform.com',
            'order_uuid' => $this->order->uuid,
            'subject' => "Order #{$this->order->id}: Media Submitted for Admin Approval",
            'html' => '<p>Media files submitted for review.</p>',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        // Verify Alice and Bob received the email
        Notification::assertSentOnDemand(SystemNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'alice@acme.com';
        });

        Notification::assertSentOnDemand(SystemNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'bob@acme.com';
        });

        // Verify inactive and opted-out admins did NOT receive the email
        Notification::assertNotSentTo(
            [$this->inactiveAdmin],
            SystemNotification::class
        );

        Notification::assertNotSentTo(
            [$this->noEmailAdmin],
            SystemNotification::class
        );

        // Verify EmailLog records were created
        $this->assertDatabaseHas('email_logs', [
            'organization_id' => $this->organization->id,
            'to_email' => 'alice@acme.com',
            'event_type' => 'admin_approval_required',
        ]);

        $this->assertDatabaseHas('email_logs', [
            'organization_id' => $this->organization->id,
            'to_email' => 'bob@acme.com',
            'event_type' => 'admin_approval_required',
        ]);
    }

    public function test_send_email_resolves_order_from_subject_when_order_uuid_omitted()
    {
        Notification::fake();

        $response = $this->actingAs($this->vendor, 'vendor-api')->postJson('/api/notifications/email', [
            'to' => 'admin',
            'subject' => "Order #{$this->order->id}: Media Submitted for Admin Approval",
            'html' => '<p>Please review media.</p>',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        Notification::assertSentOnDemand(SystemNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'alice@acme.com';
        });
    }

    public function test_store_notification_auto_assigns_organization_id_and_meta_data()
    {
        $response = $this->actingAs($this->vendor, 'vendor-api')->postJson('/api/notifications', [
            'source' => 'order',
            'source_id' => $this->order->uuid,
            'type' => 'admin_approval_required',
            'description' => "Media submitted by Vendor Victor Vendor for Order #{$this->order->id} requires Admin Approval.",
            'role' => 'admin',
            'created_by_name' => 'Victor Vendor',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('notifications', [
            'source_id' => $this->order->uuid,
            'type' => 'admin_approval_required',
            'role' => 'admin',
            'organization_id' => $this->organization->id,
        ]);

        $notification = NotificationModel::where('source_id', $this->order->uuid)->first();
        $this->assertNotNull($notification->meta_data);
        $this->assertEquals($this->order->id, $notification->meta_data['order_id']);
        $this->assertEquals($this->order->uuid, $notification->meta_data['order_uuid']);
        $this->assertEquals('742 Evergreen Terrace', $notification->meta_data['property_address']);
    }
}
