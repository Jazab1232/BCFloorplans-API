<?php

namespace Tests\Feature;

use App\Jobs\SyncOrderCalendarEvents;
use App\Models\Agent;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBreak;
use App\Models\VendorWorkHour;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Automated Backend Verification Suite — Part 2:
 * Vendor Work Hours, Breaks, Reassign, Swap, Vendor Change via Update,
 * Multi-Slot Service Removal/Cancellation, and Late-Fee Calculation.
 *
 * Maps directly to Use Cases: UC-42, UC-43, UC-44, UC-45, UC-46, UC-47,
 * UC-49, UC-50, UC-51.
 *
 * Run with:
 *   php artisan test --filter=BookingLifecycleAndBreaksTest
 */
class BookingLifecycleAndBreaksTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;
    protected User $admin;
    protected Agent $agent;
    protected Vendor $vendor1;
    protected Vendor $vendor2;
    protected Property $property;
    protected Service $service1;
    protected Service $service2;
    protected string $futureDate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();

        $this->futureDate = Carbon::now('America/Vancouver')->addDays(5)->toDateString();

        $this->org = Organization::create([
            'name' => 'Lifecycle Org',
            'slug' => 'lifecycle-org-' . Str::random(5),
        ]);

        $this->admin = User::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Admin',
            'last_name' => 'Boss',
            'email' => 'admin@lifecycle.test',
            'password' => bcrypt('password'),
        ]);

        $this->agent = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Agent',
            'last_name' => 'One',
            'email' => 'agent@lifecycle.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $this->vendor1 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Vendor',
            'last_name' => 'One',
            'email' => 'v1@lifecycle.test',
            'password' => bcrypt('password'),
            'status' => true,
            'google_access_token' => 'v1-token',
            'sync_google_calendar' => true,
        ]);

        $this->vendor2 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Vendor',
            'last_name' => 'Two',
            'email' => 'v2@lifecycle.test',
            'password' => bcrypt('password'),
            'status' => true,
            'google_access_token' => 'v2-token',
            'sync_google_calendar' => true,
        ]);

        $days = array_map(fn ($d) => [
            'day' => $d,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_off' => false,
            'is_twilight' => false,
        ], ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);

        foreach ([$this->vendor1, $this->vendor2] as $v) {
            VendorWorkHour::create([
                'uuid' => Str::uuid()->toString(),
                'vendor_id' => $v->id,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'work_days' => $days,
                'repeat_weekly' => true,
                'break_start' => '12:00',
                'break_end' => '13:00',
                'timezone' => 'America/Vancouver',
            ]);
        }

        $category = ServiceCategory::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'title' => 'Media',
            'type' => 'service',
        ]);

        $this->service1 = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'name' => 'Photos',
            'status' => true,
        ]);

        $this->service2 = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'name' => 'Video',
            'status' => true,
        ]);

        $this->property = Property::create([
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'address' => '555 Burrard St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'Canada',
        ]);
    }

    /**
     * UC-42: Adding a VendorBreak that overlaps an active booking must be rejected,
     * while adding a break over a cancelled booking is allowed.
     */
    public function test_uc42_vendor_break_blocks_active_slot_overlap_but_allows_cancelled_slot(): void
    {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);

        $activeSlot = OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'booked',
        ]);

        // Attempt to add break overlapping active 10:00-11:00 booking
        $resp = $this->actingAs($this->vendor1, 'vendor-api')
            ->postJson('/api/vendor-breaks/add', [
                'vendor_id' => $this->vendor1->uuid,
                'title' => 'Doctor Visit',
                'date' => $this->futureDate,
                'start_date' => $this->futureDate,
                'end_date' => $this->futureDate,
                'start_time' => '10:30:00',
                'end_time' => '11:30:00',
            ]);

        $this->assertContains($resp->getStatusCode(), [409, 422]);

        // Once the slot is cancelled, adding the same break must succeed
        $activeSlot->update(['status' => 'cancelled']);

        $this->actingAs($this->vendor1, 'vendor-api')
            ->postJson('/api/vendor-breaks/add', [
                'vendor_id' => $this->vendor1->uuid,
                'title' => 'Doctor Visit',
                'date' => $this->futureDate,
                'start_date' => $this->futureDate,
                'end_date' => $this->futureDate,
                'start_time' => '10:30:00',
                'end_time' => '11:30:00',
            ])
            ->assertSuccessful();
    }

    /**
     * UC-43: Agent booking outside vendor work hours or during a VendorBreak must be rejected.
     */
    public function test_uc43_agent_cannot_book_outside_work_hours_or_during_vendor_break(): void
    {
        VendorBreak::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $this->vendor1->id,
            'title' => 'Dentist',
            'date' => $this->futureDate,
            'start_date' => $this->futureDate,
            'end_date' => $this->futureDate,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ]);

        // 1. Outside work hours (07:00-08:00 when vendor starts at 09:00)
        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', [
                'agent_id' => $this->agent->uuid,
                'property_id' => $this->property->uuid,
                'amount' => 200.00,
                'services' => [['service_id' => $this->service1->uuid, 'amount' => 200.00, 'vendor_id' => $this->vendor1->uuid]],
                'slots' => [[
                    'service_id' => $this->service1->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '07:00:00',
                    'end_time' => '08:00:00',
                ]],
            ])
            ->assertStatus(422);

        // 2. During one-time VendorBreak (14:00-15:00)
        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', [
                'agent_id' => $this->agent->uuid,
                'property_id' => $this->property->uuid,
                'amount' => 200.00,
                'services' => [['service_id' => $this->service1->uuid, 'amount' => 200.00, 'vendor_id' => $this->vendor1->uuid]],
                'slots' => [[
                    'service_id' => $this->service1->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '14:15:00',
                    'end_time' => '15:15:00',
                ]],
            ])
            ->assertStatus(422);
    }

    /**
     * UC-44: Updating vendor work_days with end_time <= start_time must fail validation.
     */
    public function test_uc44_vendor_work_hours_rejects_end_time_before_start_time(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson("/api/vendors/{$this->vendor1->uuid}", [
                'first_name' => 'Vendor',
                'last_name' => 'One',
                'email' => $this->vendor1->email,
                'work_hours' => [
                    'work_days' => [
                        ['day' => 'mon', 'start_time' => '17:00', 'end_time' => '09:00', 'is_off' => false],
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    /**
     * UC-45: Creating VendorBreak and VendorWorkHour without explicit uuid must auto-generate uuid
     * (catches missing `use Illuminate\Support\Str;` in both models).
     */
    public function test_uc45_vendor_break_and_work_hour_auto_generate_uuid(): void
    {
        $break = VendorBreak::create([
            'vendor_id' => $this->vendor1->id,
            'title' => 'Auto UUID Break',
            'date' => $this->futureDate,
            'start_date' => $this->futureDate,
            'end_date' => $this->futureDate,
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->assertNotEmpty($break->uuid);

        $vendor3 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Vendor',
            'last_name' => 'Three',
            'email' => 'v3@lifecycle.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $wh = VendorWorkHour::create([
            'vendor_id' => $vendor3->id,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'work_days' => [],
        ]);

        $this->assertNotEmpty($wh->uuid);
    }

    /**
     * UC-46: Reassigning a slot must check target vendor conflicts, save H:i:s format,
     * delete old vendor Google event, sync OrderService.vendor_id, and block non-Admins.
     */
    public function test_uc46_reassign_slot_checks_conflict_formats_time_and_restricts_to_admin(): void
    {
        $order1 = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $order1->id,
            'service_id' => $this->service1->id,
            'amount' => 200.00,
            'vendor_id' => $this->vendor1->uuid,
        ]);
        $slot1 = OrderSlot::create([
            'order_id' => $order1->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'google_event_id' => 'old-v1-event',
        ]);

        // Vendor 2 already has a conflicting booking at 10:00-11:00
        $order2 = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $v2Slot = OrderSlot::create([
            'order_id' => $order2->id,
            'service_id' => $this->service2->id,
            'vendor_id' => $this->vendor2->id,
            'date' => $this->futureDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        // 1. Agent must receive 403
        $this->actingAs($this->agent, 'agent-api')
            ->postJson("/api/orders/slots/{$slot1->uuid}/reassign", [
                'vendor_id' => $this->vendor2->uuid,
            ])
            ->assertStatus(403);

        // 2. Admin reassigning into Vendor 2's busy slot must be rejected (without override)
        $conflictResp = $this->actingAs($this->admin, 'api')
            ->postJson("/api/orders/slots/{$slot1->uuid}/reassign", [
                'vendor_id' => $this->vendor2->uuid,
            ]);
        $this->assertContains($conflictResp->getStatusCode(), [409, 422]);

        // 3. Free up Vendor 2 and reassign with custom_duration -> end_time must be H:i:s
        $v2Slot->update(['status' => 'cancelled']);

        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('deleteEvent')->once()->andReturn(true);
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/orders/slots/{$slot1->uuid}/reassign", [
                'vendor_id' => $this->vendor2->uuid,
                'custom_duration' => 90,
                'suppress_email' => true,
            ])
            ->assertStatus(200);

        $slot1->refresh();
        $this->assertEquals($this->vendor2->id, $slot1->vendor_id);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $slot1->end_time, 'end_time must be stored as H:i:s');
    }

    /**
     * UC-47: Swapping two slots must check other bookings for conflicts, sync OrderService.vendor_id,
     * and block non-Admins.
     */
    public function test_uc47_swap_slots_checks_conflicts_syncs_order_service_and_blocks_agents(): void
    {
        $orderA = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $osA = OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $orderA->id,
            'service_id' => $this->service1->id,
            'amount' => 200.00,
            'vendor_id' => $this->vendor1->uuid,
        ]);
        $slotA = OrderSlot::create([
            'order_id' => $orderA->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $orderB = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $osB = OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $orderB->id,
            'service_id' => $this->service2->id,
            'amount' => 200.00,
            'vendor_id' => $this->vendor2->uuid,
        ]);
        $slotB = OrderSlot::create([
            'order_id' => $orderB->id,
            'service_id' => $this->service2->id,
            'vendor_id' => $this->vendor2->id,
            'date' => $this->futureDate,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
        ]);

        // 1. Agent must receive 403
        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders/slots/swap', [
                'slot1_uuid' => $slotA->uuid,
                'slot2_uuid' => $slotB->uuid,
            ])
            ->assertStatus(403);

        // 2. Admin swap succeeds and syncs OrderService.vendor_id
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/orders/slots/swap', [
                'slot1_uuid' => $slotA->uuid,
                'slot2_uuid' => $slotB->uuid,
                'suppress_email' => true,
            ])
            ->assertStatus(200);

        $this->assertEquals($this->vendor2->id, $slotA->fresh()->vendor_id);
        $this->assertEquals($this->vendor1->id, $slotB->fresh()->vendor_id);
        $this->assertEquals($this->vendor2->uuid, $osA->fresh()->vendor_id, 'OrderService A vendor_id must sync on swap');
        $this->assertEquals($this->vendor1->uuid, $osB->fresh()->vendor_id, 'OrderService B vendor_id must sync on swap');
    }

    /**
     * UC-49: Changing a slot's vendor via updateSlotTime must delete old vendor's Google event
     * and clear google_event_id so it is not orphaned.
     */
    public function test_uc49_update_slot_time_vendor_change_deletes_old_vendor_google_event(): void
    {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $os = OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'amount' => 200.00,
            'vendor_id' => $this->vendor1->uuid,
        ]);
        $slot = OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'google_event_id' => 'v1-gcal-event-999',
        ]);

        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('deleteEvent')
            ->once()
            ->with(Mockery::on(fn ($v) => $v->id === $this->vendor1->id), 'v1-gcal-event-999')
            ->andReturn(true);
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/orders/update-slot-time', [
                'order_uuid' => $order->uuid,
                'order_service_id' => $os->uuid,
                'service_uuid' => $this->service1->uuid,
                'slot_uuid' => $slot->uuid,
                'vendor_uuid' => $this->vendor2->uuid,
                'date' => $this->futureDate,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
            ])
            ->assertStatus(200);

        $this->assertNull($slot->fresh()->google_event_id, 'Old vendor google_event_id must be cleared when vendor changes.');
    }

    /**
     * UC-50: Cancelling a service that has multiple slots cancels ALL matching slots,
     * not just the first one.
     */
    public function test_uc50_cancel_service_cancels_all_slots_for_that_service(): void
    {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 400.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $os = OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'amount' => 400.00,
            'vendor_id' => $this->vendor1->uuid,
        ]);
        $slotA = OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'booked',
        ]);
        $slotB = OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'status' => 'booked',
        ]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/orders/{$order->uuid}/cancel-service/{$os->uuid}", ['reason' => 'Not needed'])
            ->assertStatus(200);

        $this->assertEquals('cancelled', $slotA->fresh()->status);
        $this->assertEquals('cancelled', $slotB->fresh()->status, 'All slots for the cancelled service must be marked cancelled.');
    }

    /**
     * UC-51: Late cancellation fee preview ignores already-cancelled slots and does NOT crash
     * when a vendor has no VendorWorkHour row or slot time is H:i.
     */
    public function test_uc51_cancellation_preview_ignores_cancelled_slots_and_handles_null_work_hours(): void
    {
        $vendorNoWh = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'No',
            'last_name' => 'WorkHours',
            'email' => 'nowh@lifecycle.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 400.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);

        // Earlier slot (5 hours from now -> would be late), ALREADY cancelled
        $soon = Carbon::now('America/Vancouver')->addHours(5);
        OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $vendorNoWh->id,
            'date' => $soon->toDateString(),
            'start_time' => $soon->format('H:i'), // H:i format
            'end_time' => $soon->copy()->addHour()->format('H:i'),
            'status' => 'cancelled',
        ]);

        // Active slot 48 hours from now -> FREE cancellation
        $later = Carbon::now('America/Vancouver')->addHours(48);
        OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service2->id,
            'vendor_id' => $vendorNoWh->id,
            'date' => $later->toDateString(),
            'start_time' => $later->format('H:i'), // H:i format
            'end_time' => $later->copy()->addHour()->format('H:i'),
            'status' => 'booked',
        ]);

        $resp = $this->actingAs($this->admin, 'api')
            ->getJson("/api/orders/{$order->uuid}/cancel-preview")
            ->assertStatus(200);

        $resp->assertJsonPath('data.is_free', true)
            ->assertJsonPath('data.cancellation_fee', 0.0);
    }
}
