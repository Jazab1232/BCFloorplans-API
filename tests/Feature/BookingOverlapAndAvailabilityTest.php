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
use App\Models\VendorSetting;
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
 * Automated Backend Verification Suite — Part 1:
 * Booking Creation, Overlap Prevention, Duplicate Guard, Cancelled Slot Release,
 * Consecutive Booking, Custom Duration/Buffer, and Time Validation.
 *
 * Maps directly to Use Cases: UC-03, UC-09, UC-12, UC-13, UC-14, UC-17,
 * UC-21, UC-22, UC-23, UC-24, UC-26, UC-27, UC-28, UC-30, UC-38.
 *
 * Run with:
 *   php artisan test --filter=BookingOverlapAndAvailabilityTest
 */
class BookingOverlapAndAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;
    protected User $admin;
    protected Agent $agent;
    protected Vendor $vendor1;
    protected Vendor $vendor2;
    protected Property $property1;
    protected Property $property2;
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
            'name' => 'BCF Test Org',
            'slug' => 'bcf-test-org-' . Str::random(5),
        ]);

        $this->admin = User::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@bcf-test.com',
            'password' => bcrypt('password'),
        ]);

        $this->agent = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Alice',
            'last_name' => 'Agent',
            'email' => 'agent@bcf-test.com',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $this->vendor1 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Victor',
            'last_name' => 'One',
            'email' => 'vendor1@bcf-test.com',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $this->vendor2 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'first_name' => 'Vanessa',
            'last_name' => 'Two',
            'email' => 'vendor2@bcf-test.com',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $this->seedDefaultWorkHours($this->vendor1);
        $this->seedDefaultWorkHours($this->vendor2);

        $category = ServiceCategory::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'title' => 'Photography',
            'type' => 'service',
        ]);

        $this->service1 = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'name' => 'HDR Photography',
            'status' => true,
            'is_travel_required' => true,
            'base_duration_mins' => 60,
        ]);

        $this->service2 = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'name' => '2D Floor Plan',
            'status' => true,
            'is_travel_required' => true,
            'base_duration_mins' => 60,
        ]);

        $this->property1 = Property::create([
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'address' => '100 Robson St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'postal_code' => 'V6B 2A7',
            'country' => 'Canada',
            'square_footage' => 2000,
        ]);

        $this->property2 = Property::create([
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'address' => '200 Granville St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'postal_code' => 'V6C 1S4',
            'country' => 'Canada',
            'square_footage' => 2000,
        ]);
    }

    protected function seedDefaultWorkHours(Vendor $vendor, string $timezone = 'America/Vancouver'): VendorWorkHour
    {
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $workDays = array_map(fn ($day) => [
            'day' => $day,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'is_off' => false,
            'is_twilight' => true,
        ], $days);

        return VendorWorkHour::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendor->id,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'work_days' => $workDays,
            'repeat_weekly' => true,
            'break_start' => '12:00',
            'break_end' => '13:00',
            'commute_minutes' => 15,
            'timezone' => $timezone,
        ]);
    }

    protected function makeOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $this->agent->uuid,
            'property_id' => $this->property1->uuid,
            'amount' => 250.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
            'services' => [
                [
                    'service_id' => $this->service1->uuid,
                    'amount' => 250.00,
                    'vendor_id' => $this->vendor1->uuid,
                ],
            ],
            'slots' => [
                [
                    'service_id' => $this->service1->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '09:00:00',
                    'end_time' => '10:00:00',
                    'schedule_override' => false,
                ],
            ],
        ], $overrides);
    }

    /**
     * UC-03: Multi-service order with 2 different vendors at the same property succeeds
     * and dispatches calendar sync.
     */
    public function test_uc03_multi_service_order_with_two_different_vendors_succeeds(): void
    {
        $payload = $this->makeOrderPayload([
            'amount' => 450.00,
            'services' => [
                ['service_id' => $this->service1->uuid, 'amount' => 250.00, 'vendor_id' => $this->vendor1->uuid],
                ['service_id' => $this->service2->uuid, 'amount' => 200.00, 'vendor_id' => $this->vendor2->uuid],
            ],
            'slots' => [
                [
                    'service_id' => $this->service1->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '09:00:00',
                    'end_time' => '10:00:00',
                ],
                [
                    'service_id' => $this->service2->uuid,
                    'vendor_id' => $this->vendor2->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '09:00:00',
                    'end_time' => '10:00:00',
                ],
            ],
        ]);

        $response = $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201);
        $this->assertEquals(2, OrderSlot::count());
        Queue::assertDispatched(SyncOrderCalendarEvents::class);
    }

    /**
     * UC-09: Consecutive booking rule (next_booking_slot_only) must ignore slots belonging
     * to Cancelled orders.
     */
    public function test_uc09_consecutive_booking_ignores_cancelled_order_slots(): void
    {
        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $this->vendor1->id,
            'next_booking_slot_only' => true,
        ]);

        // Create a cancelled order holding 08:00-09:00
        $cancelledOrder = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property1->id,
            'amount' => 200.00,
            'order_status' => 'Cancelled',
            'payment_status' => 'UNPAID',
        ]);
        OrderSlot::create([
            'order_id' => $cancelledOrder->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'status' => 'booked', // Simulates current cancel() bug leaving slot booked
        ]);

        // Booking at 08:00:00 (work start) should SUCCEED because the 08:00-09:00 order is Cancelled
        $payload = $this->makeOrderPayload([
            'slots' => [[
                'service_id' => $this->service1->uuid,
                'vendor_id' => $this->vendor1->uuid,
                'date' => $this->futureDate,
                'start_time' => '08:00:00',
                'end_time' => '09:00:00',
            ]],
        ]);

        $response = $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201);
    }

    /**
     * UC-12: Admin can trim/extend a booked slot via POST /orders/update-slot-time,
     * while an Agent receives 403 Forbidden.
     */
    public function test_uc12_update_slot_time_is_admin_only(): void
    {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property1->id,
            'amount' => 250.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $orderService = OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'amount' => 250.00,
            'vendor_id' => $this->vendor1->uuid,
        ]);
        $slot = OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
        ]);

        $payload = [
            'order_uuid' => $order->uuid,
            'order_service_id' => $orderService->uuid,
            'service_uuid' => $this->service1->uuid,
            'slot_uuid' => $slot->uuid,
            'vendor_uuid' => $this->vendor1->uuid,
            'date' => $this->futureDate,
            'start_time' => '14:00:00',
            'end_time' => '15:45:00',
        ];

        // Agent must be blocked with 403
        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders/update-slot-time', $payload)
            ->assertStatus(403);

        // Admin must succeed with 200
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/orders/update-slot-time', $payload)
            ->assertStatus(200);

        $this->assertEquals('15:45:00', $slot->fresh()->end_time);
    }

    /**
     * UC-13 & UC-14: Admin creating an order with custom_duration and buffer_minutes
     * persists both fields on OrderSlot.
     */
    public function test_uc14_store_persists_custom_duration_and_buffer_minutes(): void
    {
        $payload = $this->makeOrderPayload([
            'slots' => [[
                'service_id' => $this->service1->uuid,
                'vendor_id' => $this->vendor1->uuid,
                'date' => $this->futureDate,
                'start_time' => '09:00:00',
                'end_time' => '09:45:00',
                'custom_duration' => 45,
                'buffer_minutes' => 15,
            ]],
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/orders', $payload);

        $response->assertStatus(201);
        $slot = OrderSlot::first();
        $this->assertNotNull($slot);
        $this->assertEquals(45, $slot->custom_duration, 'store() must persist custom_duration');
        $this->assertEquals(15, $slot->buffer_minutes, 'store() must persist buffer_minutes');
    }

    /**
     * UC-17: Agents must NOT be able to bypass Google Calendar conflict checks by sending
     * schedule_override=true, and schedule_override must NOT default to true.
     */
    public function test_uc17_agent_cannot_bypass_google_calendar_conflict_with_schedule_override(): void
    {
        $this->vendor1->update([
            'google_access_token' => 'fake-token',
            'sync_google_calendar' => true,
        ]);

        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('getEvents')->andReturn([
            [
                'id' => 'ext-gcal-evt-1',
                'summary' => 'Dentist Appointment',
                'start' => "{$this->futureDate}T09:00:00-07:00",
                'end' => "{$this->futureDate}T10:30:00-07:00",
                'all_day' => false,
            ],
        ]);
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $payload = $this->makeOrderPayload([
            'slots' => [[
                'service_id' => $this->service1->uuid,
                'vendor_id' => $this->vendor1->uuid,
                'date' => $this->futureDate,
                'start_time' => '09:30:00',
                'end_time' => '10:30:00',
                'schedule_override' => true, // Spoofed by Agent
            ]],
        ]);

        $response = $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $payload);

        $response->assertStatus(422);
    }

    /**
     * UC-21: Exact duplicate booking for the same vendor, date, and time MUST be rejected.
     */
    public function test_uc21_prevents_exact_duplicate_booking_for_same_vendor(): void
    {
        $payload = $this->makeOrderPayload();

        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $payload)
            ->assertStatus(201);

        // Second booking for exact same vendor/date/time must be rejected (409 or 422)
        $secondResponse = $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $payload);

        $this->assertContains(
            $secondResponse->getStatusCode(),
            [409, 422],
            'Duplicate booking for same vendor/time must be rejected with 409 or 422.'
        );
        $this->assertEquals(1, OrderSlot::count());
    }

    /**
     * UC-22: Partial overlap (existing 10:00-11:00 vs new 10:30-11:30, 09:30-10:30, or 09:00-12:00)
     * MUST be rejected.
     */
    public function test_uc22_prevents_partial_and_enclosing_slot_overlaps(): void
    {
        $basePayload = $this->makeOrderPayload([
            'slots' => [[
                'service_id' => $this->service1->uuid,
                'vendor_id' => $this->vendor1->uuid,
                'date' => $this->futureDate,
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
            ]],
        ]);

        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $basePayload)
            ->assertStatus(201);

        $overlappingWindows = [
            ['10:30:00', '11:30:00'], // Overlaps tail
            ['09:30:00', '10:30:00'], // Overlaps head
            ['09:00:00', '12:00:00'], // Encloses existing
            ['10:15:00', '10:45:00'], // Contained within existing
        ];

        foreach ($overlappingWindows as [$start, $end]) {
            $resp = $this->actingAs($this->agent, 'agent-api')
                ->postJson('/api/orders', $this->makeOrderPayload([
                    'slots' => [[
                        'service_id' => $this->service1->uuid,
                        'vendor_id' => $this->vendor1->uuid,
                        'date' => $this->futureDate,
                        'start_time' => $start,
                        'end_time' => $end,
                    ]],
                ]));

            $this->assertContains(
                $resp->getStatusCode(),
                [409, 422],
                "Overlap window {$start}-{$end} against 10:00-11:00 must be rejected."
            );
        }
    }

    /**
     * UC-23: Exact boundary touch (09:00-10:00 followed by 10:00-11:00 at same property)
     * is NOT an overlap and must succeed.
     */
    public function test_uc23_allows_back_to_back_exact_boundary_slots(): void
    {
        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $this->makeOrderPayload([
                'slots' => [[
                    'service_id' => $this->service1->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '09:00:00',
                    'end_time' => '10:00:00',
                ]],
            ]))
            ->assertStatus(201);

        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $this->makeOrderPayload([
                'slots' => [[
                    'service_id' => $this->service2->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '10:00:00',
                    'end_time' => '11:00:00',
                ]],
            ]))
            ->assertStatus(201);

        $this->assertEquals(2, OrderSlot::count());
    }

    /**
     * UC-24: Intra-request overlapping slots for the same vendor in a single order MUST be rejected.
     */
    public function test_uc24_rejects_overlapping_slots_within_same_order_request(): void
    {
        $payload = $this->makeOrderPayload([
            'amount' => 450.00,
            'services' => [
                ['service_id' => $this->service1->uuid, 'amount' => 250.00, 'vendor_id' => $this->vendor1->uuid],
                ['service_id' => $this->service2->uuid, 'amount' => 200.00, 'vendor_id' => $this->vendor1->uuid],
            ],
            'slots' => [
                [
                    'service_id' => $this->service1->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '09:00:00',
                    'end_time' => '10:00:00',
                ],
                [
                    'service_id' => $this->service2->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '09:30:00',
                    'end_time' => '10:30:00',
                ],
            ],
        ]);

        $response = $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $payload);

        $this->assertContains($response->getStatusCode(), [409, 422]);
    }

    /**
     * UC-26 & UC-27: Cancelling an order must mark its OrderSlots as 'cancelled',
     * exclude them from GET /order-slots and GET /vendors, and allow immediate rebooking.
     */
    public function test_uc26_cancelled_order_releases_slots_and_excludes_from_availability_endpoints(): void
    {
        $createResp = $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $this->makeOrderPayload())
            ->assertStatus(201);

        $orderUuid = $createResp->json('data.uuid');
        $slot = OrderSlot::firstOrFail();

        // Cancel the order
        $this->actingAs($this->agent, 'agent-api')
            ->postJson("/api/orders/{$orderUuid}/cancel", ['reason' => 'Client rescheduled'])
            ->assertStatus(200);

        // 1. Slot status must be marked 'cancelled'
        $this->assertEquals('cancelled', $slot->fresh()->status, 'Order cancellation must mark slots cancelled.');

        // 2. GET /order-slots must NOT return cancelled slots
        $slotsResp = $this->actingAs($this->admin, 'api')
            ->getJson('/api/order-slots')
            ->assertStatus(200);
        $returnedSlotUuids = collect($slotsResp->json('data') ?? $slotsResp->json())->pluck('uuid')->all();
        $this->assertNotContains($slot->uuid, $returnedSlotUuids, 'Cancelled order slot leaked in /order-slots.');

        // 3. Rebooking the exact same slot must now succeed
        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $this->makeOrderPayload())
            ->assertStatus(201);
    }

    /**
     * UC-28: Updating an order without changing its time slot must NOT self-conflict.
     */
    public function test_uc28_updating_order_does_not_self_conflict_with_existing_slot(): void
    {
        $createResp = $this->actingAs($this->admin, 'api')
            ->postJson('/api/orders', $this->makeOrderPayload())
            ->assertStatus(201);

        $orderUuid = $createResp->json('data.uuid');

        $updateResp = $this->actingAs($this->admin, 'api')
            ->putJson("/api/orders/{$orderUuid}", $this->makeOrderPayload([
                'notes' => [['name' => 'Admin', 'note' => 'Updated note', 'date' => now()->toDateTimeString()]],
            ]));

        $updateResp->assertStatus(200);
    }

    /**
     * UC-30: Inverted or zero-length slots (end_time <= start_time) and cross-midnight wrap
     * must be rejected with 422.
     */
    public function test_uc30_rejects_inverted_and_zero_length_slots(): void
    {
        foreach ([['11:00:00', '09:00:00'], ['10:00:00', '10:00:00']] as [$start, $end]) {
            $resp = $this->actingAs($this->admin, 'api')
                ->postJson('/api/orders', $this->makeOrderPayload([
                    'slots' => [[
                        'service_id' => $this->service1->uuid,
                        'vendor_id' => $this->vendor1->uuid,
                        'date' => $this->futureDate,
                        'start_time' => $start,
                        'end_time' => $end,
                    ]],
                ]));

            $resp->assertStatus(422);
        }
    }

    /**
     * UC-38: store() must apply vendor payment_per_km fallback when km_price is omitted,
     * matching update()/processSlots() behavior.
     */
    public function test_uc38_store_applies_vendor_travel_rate_fallback(): void
    {
        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $this->vendor1->id,
            'payment_per_km' => 1.50,
        ]);

        $payload = $this->makeOrderPayload([
            'slots' => [[
                'service_id' => $this->service1->uuid,
                'vendor_id' => $this->vendor1->uuid,
                'date' => $this->futureDate,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'distance' => '20',
                // km_price intentionally omitted
            ]],
        ]);

        $this->actingAs($this->agent, 'agent-api')
            ->postJson('/api/orders', $payload)
            ->assertStatus(201);

        $slot = OrderSlot::firstOrFail();
        $this->assertEquals(1.50, (float) $slot->km_price);
    }
}
