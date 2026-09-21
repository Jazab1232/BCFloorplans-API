<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Order;
use App\Models\OrderSlot;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SyncOrderCalendarEvents;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $agent;
    protected $vendor;
    protected $organization;
    protected $order;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // Prevent dispatching actual sync jobs

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org-' . rand(1000, 9999),
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

        $this->vendor = Vendor::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Bill',
            'last_name' => 'Vendor',
            'email' => 'vendor@test.com',
            'password' => bcrypt('password'),
            'status' => true,
            'timezone' => 'America/Toronto'
        ]);

        // Create order
        $this->order = Order::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'agent_id' => $this->agent->id,
            'amount' => 500.00,
            'order_status' => 'Pending',
            'payment_status' => 'UNPAID',
            'property_address' => '123 Main St, Toronto',
        ]);
    }

    /**
     * Test cancellation preview in free cancellation window.
     */
    public function test_preview_free_cancellation()
    {
        // Set slot to 30 hours from now
        $slotDate = Carbon::now('America/Toronto')->addHours(30);
        OrderSlot::create([
            'order_id' => $this->order->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        $response = $this->actingAs($this->agent, 'api')
            ->getJson("/api/orders/{$this->order->uuid}/cancel-preview");

        $response->assertStatus(200)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.is_free', true)
            ->assertJsonPath('data.cancellation_fee', 0.0);
    }

    /**
     * Test cancellation preview in late cancellation window.
     */
    public function test_preview_late_cancellation()
    {
        // Set slot to 10 hours from now (within 24 hours threshold)
        $slotDate = Carbon::now('America/Toronto')->addHours(10);
        OrderSlot::create([
            'order_id' => $this->order->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        $response = $this->actingAs($this->agent, 'api')
            ->getJson("/api/orders/{$this->order->uuid}/cancel-preview");

        $response->assertStatus(200)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.is_free', false)
            ->assertJsonPath('data.cancellation_fee', 125.00); // 25% of 500.00
    }

    /**
     * Test late cancellation execution on unpaid order.
     */
    public function test_cancel_unpaid_order_late()
    {
        $slotDate = Carbon::now('America/Toronto')->addHours(10);
        OrderSlot::create([
            'order_id' => $this->order->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        // Create an unpaid invoice
        $invoice = Invoice::create([
            'organization_id' => $this->organization->id,
            'order_id' => $this->order->id,
            'agent_id' => $this->agent->id,
            'status' => 'issued',
            'subtotal' => 500.00,
            'tax_rate' => 13.00,
            'tax_amount' => 65.00,
            'total' => 565.00,
            'paid_amount' => 0.00,
            'currency' => 'cad',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($this->agent, 'api')
            ->postJson("/api/orders/{$this->order->uuid}/cancel", [
                'reason' => 'Change of plans'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.order_status', 'Cancelled')
            ->assertJsonPath('data.cancellation_fee', 125.00);

        // Assert original invoice was voided
        $this->assertEquals('void', $invoice->fresh()->status);

        // Assert new fee invoice was created
        $feeInvoice = Invoice::where('order_id', $this->order->id)->where('notes', 'like', 'Late Cancellation Fee%')->first();
        $this->assertNotNull($feeInvoice);
        $this->assertEquals(125.00, (float)$feeInvoice->subtotal);
        $this->assertEquals('issued', $feeInvoice->status);

        // Assert calendar sync job was dispatched
        Queue::assertDispatched(SyncOrderCalendarEvents::class);
    }

    /**
     * Test late cancellation execution on paid order.
     */
    public function test_cancel_paid_order_late()
    {
        $slotDate = Carbon::now('America/Toronto')->addHours(10);
        OrderSlot::create([
            'order_id' => $this->order->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        // Create a paid invoice
        $invoice = Invoice::create([
            'organization_id' => $this->organization->id,
            'order_id' => $this->order->id,
            'agent_id' => $this->agent->id,
            'status' => 'paid',
            'subtotal' => 500.00,
            'tax_rate' => 13.00,
            'tax_amount' => 65.00,
            'total' => 565.00,
            'paid_amount' => 565.00,
            'currency' => 'cad',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($this->agent, 'api')
            ->postJson("/api/orders/{$this->order->uuid}/cancel", [
                'reason' => 'No longer needed'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.order_status', 'Cancelled')
            ->assertJsonPath('data.cancellation_fee', 125.00);

        // Proportional refund checks
        // 75% of 565.00 paid total = 423.75 refund
        $updatedInvoice = $invoice->fresh();
        $this->assertEquals(423.75, (float)$updatedInvoice->refunded_amount);
        $this->assertEquals('partially_paid', $updatedInvoice->status);
    }

    /**
     * Test cancellation is blocked if allow_cancel_after_threshold is false.
     */
    public function test_cancellation_blocked_after_threshold()
    {
        // Seed setting to block cancellation after threshold
        Setting::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'org_id' => $this->organization->uuid,
            'key' => 'portal_settings',
            'value' => [
                'cancellation_threshold_hours' => 24,
                'cancellation_fee_percentage' => 25.00,
                'allow_cancel_after_threshold' => false,
                'show_org_details_on_empty_schedule' => false,
                'disable_next_day_booking' => false,
                'booking_cutoff_time' => '17:00'
            ]
        ]);

        $slotDate = Carbon::now('America/Toronto')->addHours(10);
        OrderSlot::create([
            'order_id' => $this->order->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        // Preview should show can_cancel = false
        $previewResponse = $this->actingAs($this->agent, 'api')
            ->getJson("/api/orders/{$this->order->uuid}/cancel-preview");

        $previewResponse->assertStatus(200)
            ->assertJsonPath('data.can_cancel', false);

        // Post cancellation should return HTTP 422
        $cancelResponse = $this->actingAs($this->agent, 'api')
            ->postJson("/api/orders/{$this->order->uuid}/cancel");

        $cancelResponse->assertStatus(422);
    }

    /**
     * Test authorization rules.
     */
    public function test_unauthorized_user_cannot_cancel()
    {
        $otherAgent = Agent::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Jane',
            'last_name' => 'Agent',
            'email' => 'jane@test.com',
            'password' => bcrypt('password'),
            'status' => true
        ]);

        // Other agent tries to cancel
        $response = $this->actingAs($otherAgent, 'api')
            ->postJson("/api/orders/{$this->order->uuid}/cancel");

        $response->assertStatus(403);
    }

    /**
     * Test single service cancellation preview in free window.
     */
    public function test_preview_service_cancellation_free()
    {
        $service = \App\Models\Service::create([
            'name' => 'Test Photo Service'
        ]);

        $orderService = OrderService::create([
            'order_id' => $this->order->id,
            'service_id' => $service->id,
            'amount' => 200.00,
        ]);

        $slotDate = Carbon::now('America/Toronto')->addHours(30);
        OrderSlot::create([
            'order_id' => $this->order->id,
            'service_id' => $service->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        $response = $this->actingAs($this->agent, 'api')
            ->getJson("/api/orders/{$this->order->uuid}/cancel-service-preview/{$orderService->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.is_free', true)
            ->assertJsonPath('data.cancellation_fee', 0.0);
    }

    /**
     * Test single service cancellation execution under free window.
     */
    public function test_cancel_service_free()
    {
        $service = \App\Models\Service::create([
            'name' => 'Test Video Service'
        ]);

        $orderService = OrderService::create([
            'order_id' => $this->order->id,
            'service_id' => $service->id,
            'amount' => 200.00,
        ]);

        $slotDate = Carbon::now('America/Toronto')->addHours(30);
        $slot = OrderSlot::create([
            'order_id' => $this->order->id,
            'service_id' => $service->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        $response = $this->actingAs($this->agent, 'api')
            ->postJson("/api/orders/{$this->order->uuid}/cancel-service/{$orderService->uuid}");

        $response->assertStatus(200);

        // Assert database records deleted
        $this->assertDatabaseMissing('order_services', ['id' => $orderService->id]);
        $this->assertDatabaseMissing('order_slots', ['id' => $slot->id]);

        // Assert order amount decreased
        $this->assertEquals(300.00, (float)$this->order->fresh()->amount); // 500.00 - 200.00
    }

    /**
     * Test single service cancellation execution late (fee applies, unpaid invoice).
     */
    public function test_cancel_service_late_unpaid()
    {
        $service = \App\Models\Service::create([
            'name' => 'Test Video Service'
        ]);

        $orderService = OrderService::create([
            'order_id' => $this->order->id,
            'service_id' => $service->id,
            'amount' => 200.00,
        ]);

        $slotDate = Carbon::now('America/Toronto')->addHours(10);
        $slot = OrderSlot::create([
            'order_id' => $this->order->id,
            'service_id' => $service->id,
            'vendor_id' => $this->vendor->id,
            'date' => $slotDate->toDateString(),
            'start_time' => $slotDate->toTimeString(),
            'end_time' => $slotDate->copy()->addHour()->toTimeString(),
        ]);

        // Individual service invoice (unpaid)
        $serviceInvoice = Invoice::create([
            'organization_id' => $this->organization->id,
            'order_id' => $this->order->id,
            'agent_id' => $this->agent->id,
            'status' => 'issued',
            'subtotal' => 200.00,
            'tax_rate' => 13.00,
            'tax_amount' => 26.00,
            'total' => 226.00,
            'paid_amount' => 0.00,
            'currency' => 'cad',
            'issued_at' => now(),
            'notes' => 'Service Invoice: Test Video Service'
        ]);

        $serviceInvoice->items()->create([
            'order_service_id' => $orderService->id,
            'description' => 'Test Video Service',
            'quantity' => 1,
            'unit_price' => 200.00,
            'amount' => 200.00
        ]);

        $response = $this->actingAs($this->agent, 'api')
            ->postJson("/api/orders/{$this->order->uuid}/cancel-service/{$orderService->uuid}");

        $response->assertStatus(200);

        // Assert service invoice voided
        $this->assertEquals('void', $serviceInvoice->fresh()->status);

        // Assert fee invoice created (25% of 200.00 = 50.00)
        $feeInvoice = Invoice::where('order_id', $this->order->id)->where('notes', 'like', 'Late Cancellation Fee%')->first();
        $this->assertNotNull($feeInvoice);
        $this->assertEquals(50.00, (float)$feeInvoice->subtotal);

        // Assert order stats updated
        $this->assertEquals(300.00, (float)$this->order->fresh()->amount); // 500.00 - 200.00
        $this->assertEquals(50.00, (float)$this->order->fresh()->cancellation_fee);
    }
}
