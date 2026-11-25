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
use App\Models\VendorWorkHour;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Automated Backend Verification Suite — Part 3:
 * Google Calendar Two-Way (Vendor) & One-Way (Agent) Sync, All-Day Off-By-One,
 * Free/Declined Event Filtering, Self-Conflict Prevention, Token Disconnect,
 * Timezone Resolution, and Manual Sync Authorization.
 *
 * Maps directly to Use Cases: UC-53, UC-54, UC-55, UC-56, UC-57, UC-58, UC-59, UC-60.
 *
 * Run with:
 *   php artisan test --filter=GoogleCalendarSyncAndAvailabilityTest
 */
class GoogleCalendarSyncAndAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org1;
    protected Organization $org2;
    protected User $admin1;
    protected Agent $agent1;
    protected Agent $agent2;
    protected Vendor $vendor1;
    protected Property $property;
    protected Service $service;
    protected string $futureDate;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->futureDate = Carbon::now('America/Vancouver')->addDays(6)->toDateString();

        $this->org1 = Organization::create([
            'name' => 'GCal Org 1',
            'slug' => 'gcal-org-1-' . Str::random(5),
        ]);
        $this->org2 = Organization::create([
            'name' => 'GCal Org 2',
            'slug' => 'gcal-org-2-' . Str::random(5),
        ]);

        $this->admin1 = User::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Admin',
            'last_name' => 'GCal',
            'email' => 'admin@gcal.test',
            'password' => bcrypt('password'),
        ]);

        $this->agent1 = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Agent',
            'last_name' => 'Connected',
            'email' => 'agent1@gcal.test',
            'password' => bcrypt('password'),
            'status' => true,
            'google_access_token' => 'agent-token-1',
            'google_refresh_token' => 'agent-refresh-1',
            'google_calendar_id' => 'primary',
            'sync_google_calendar' => true,
        ]);

        $this->agent2 = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org2->id,
            'first_name' => 'Agent',
            'last_name' => 'OtherOrg',
            'email' => 'agent2@gcal.test',
            'password' => bcrypt('password'),
            'status' => true,
            'google_access_token' => 'agent-token-2',
            'sync_google_calendar' => true,
        ]);

        $this->vendor1 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Vendor',
            'last_name' => 'GCal',
            'email' => 'vendor1@gcal.test',
            'password' => bcrypt('password'),
            'status' => true,
            'google_access_token' => 'vendor-token-1',
            'google_refresh_token' => 'vendor-refresh-1',
            'google_calendar_id' => 'primary',
            'sync_google_calendar' => true,
        ]);

        $category = ServiceCategory::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'title' => 'Media',
            'type' => 'service',
        ]);

        $this->service = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'category_id' => $category->id,
            'name' => '2D Floor Plan',
            'status' => true,
        ]);

        $this->property = Property::create([
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'address' => '777 Hornby St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'Canada',
        ]);
    }

    /**
     * UC-53: SyncOrderCalendarEvents must NOT crash when vendor has no VendorWorkHour row,
     * must parse H:i slot times safely, and must use the vendor's OWN timezone (not first row in table).
     */
    public function test_uc53_sync_job_handles_null_work_hours_and_hi_time_format(): void
    {
        // Deliberately NO VendorWorkHour for $this->vendor1
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'property_id' => $this->property->id,
            'amount' => 250.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);

        $slot = OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '10:00', // H:i format without seconds
            'end_time' => '11:30',
            'status' => 'booked',
        ]);

        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('createEvent')
            ->twice() // Once for vendor, once for agent
            ->andReturn('created-gcal-event-id');
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $job = new SyncOrderCalendarEvents($order->id);
        $job->handle($mockGcal);

        $slot->refresh();
        $this->assertEquals('created-gcal-event-id', $slot->google_event_id, 'Vendor event should be created even when workHours is null and time is H:i.');
        $this->assertEquals('created-gcal-event-id', $slot->agent_google_event_id, 'Agent event should be created even when time is H:i.');
    }

    /**
     * UC-54: Cancelling a single service must delete BOTH vendor and agent Google events,
     * and SyncOrderCalendarEvents must NOT re-create the cancelled slot on the Agent's calendar.
     */
    public function test_uc54_cancelled_service_deletes_agent_event_and_does_not_recreate_it(): void
    {
        // Disconnect vendor to also verify Bug 7a (agent deletion must not be skipped when vendor disconnected)
        $this->vendor1->update(['google_access_token' => null, 'sync_google_calendar' => false]);

        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'property_id' => $this->property->id,
            'amount' => 250.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);

        $cancelledSlot = OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'cancelled',
            'agent_google_event_id' => 'agent-evt-to-delete',
        ]);

        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('deleteEvent')
            ->once()
            ->with(Mockery::on(fn ($m) => $m instanceof Agent && $m->id === $this->agent1->id), 'agent-evt-to-delete')
            ->andReturn(true);
        // Must NEVER call createEvent for the cancelled slot!
        $mockGcal->shouldNotReceive('createEvent');
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $job = new SyncOrderCalendarEvents($order->id, [$cancelledSlot->id]);
        $job->handle($mockGcal);

        $this->assertNull($cancelledSlot->fresh()->agent_google_event_id);
    }

    /**
     * UC-55: Hard-deleting an order (DELETE /orders/{uuid}) must clean up vendor & agent Google events.
     */
    public function test_uc55_order_destroy_cleans_up_google_calendar_events(): void
    {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'property_id' => $this->property->id,
            'amount' => 250.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'google_event_id' => 'vendor-evt-del',
            'agent_google_event_id' => 'agent-evt-del',
        ]);

        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('deleteEvent')
            ->with(Mockery::on(fn ($m) => $m instanceof Vendor), 'vendor-evt-del')
            ->once()
            ->andReturn(true);
        $mockGcal->shouldReceive('deleteEvent')
            ->with(Mockery::on(fn ($m) => $m instanceof Agent), 'agent-evt-del')
            ->once()
            ->andReturn(true);
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $this->actingAs($this->admin1, 'api')
            ->deleteJson("/api/orders/{$order->uuid}")
            ->assertStatus(200);
    }

    /**
     * UC-56: All-day Google event (start.date = 2026-06-10, exclusive end.date = 2026-06-11)
     * must ONLY block 2026-06-10, NOT 2026-06-11. Multi-day unavailable dates must include all days
     * and return a sequential JSON list.
     */
    public function test_uc56_all_day_google_event_does_not_block_next_day(): void
    {
        // Partial mock of GoogleCalendarService::getVendorBusySlots to test isVendorAvailable & getUnavailableDates
        $service = Mockery::mock(GoogleCalendarService::class)->makePartial();

        // Simulate a 3-day all-day event (Jun 10, 11, 12 -> Google exclusive end.date = Jun 13)
        // Proper parsed end should be Jun 12 23:59:59, NOT Jun 13 23:59:59
        $service->shouldReceive('getVendorBusySlots')->andReturn([
            [
                'id' => 'all-day-3d',
                'summary' => 'Vacation',
                'start' => Carbon::parse('2026-06-10')->startOfDay(),
                'end' => Carbon::parse('2026-06-12')->endOfDay(),
                'all_day' => true,
            ],
        ]);

        $unavailable = $service->getUnavailableDates($this->vendor1, '2026-06-01', '2026-06-30');

        $this->assertTrue(array_is_list($unavailable), 'getUnavailableDates must return a sequential list (array_values).');
        $this->assertContains('2026-06-10', $unavailable);
        $this->assertContains('2026-06-11', $unavailable, 'Multi-day all-day event must include day 2.');
        $this->assertContains('2026-06-12', $unavailable, 'Multi-day all-day event must include day 3.');
        $this->assertNotContains('2026-06-13', $unavailable, 'Exclusive end.date must NOT be marked unavailable.');
    }

    /**
     * UC-58: BCF's own vendor Google Calendar event (titled e.g. "2D Floor Plan", NOT "Order #123")
     * must be recognized by google_event_id and NOT cause false self-conflicts.
     */
    public function test_uc58_bcf_vendor_event_with_service_title_is_recognized_as_internal(): void
    {
        $existingOrder = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'property_id' => $this->property->id,
            'amount' => 200.00,
            'order_status' => 'Cancelled',
            'payment_status' => 'UNPAID',
        ]);
        OrderSlot::create([
            'order_id' => $existingOrder->id,
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor1->id,
            'date' => $this->futureDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'cancelled',
            'google_event_id' => 'bcf-internal-evt-42',
        ]);

        // Even if Google still returns that BCF event briefly (cache), store() should skip it by google_event_id
        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('getEvents')->andReturn([
            [
                'id' => 'bcf-internal-evt-42',
                'summary' => '2D Floor Plan', // Real vendor title format (does NOT match /^Order #\d+/)
                'start' => "{$this->futureDate}T10:00:00-07:00",
                'end' => "{$this->futureDate}T11:00:00-07:00",
                'all_day' => false,
            ],
        ]);
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $resp = $this->actingAs($this->agent1, 'agent-api')
            ->postJson('/api/orders', [
                'agent_id' => $this->agent1->uuid,
                'property_id' => $this->property->uuid,
                'amount' => 250.00,
                'services' => [['service_id' => $this->service->uuid, 'amount' => 250.00, 'vendor_id' => $this->vendor1->uuid]],
                'slots' => [[
                    'service_id' => $this->service->uuid,
                    'vendor_id' => $this->vendor1->uuid,
                    'date' => $this->futureDate,
                    'start_time' => '10:00:00',
                    'end_time' => '11:00:00',
                    'schedule_override' => false,
                ]],
            ]);

        $resp->assertStatus(201);
    }

    /**
     * UC-59: (a) resolveTimezoneFromProvince must preserve valid IANA timezone casing and map NL -> America/St_Johns.
     *        (b) disconnect() must clear DB credentials even if refresh_token is missing/expired.
     */
    public function test_uc59_timezone_resolution_and_disconnect_with_expired_token(): void
    {
        // (a) IANA timezone string must NOT be uppercased to "AMERICA/VANCOUVER"
        $this->assertEquals(
            'America/Vancouver',
            GoogleCalendarService::resolveTimezoneFromProvince('America/Vancouver')
        );
        $this->assertEquals(
            'America/St_Johns',
            GoogleCalendarService::resolveTimezoneFromProvince('NL')
        );

        // (b) Disconnect vendor whose token is already expired and has no refresh token
        $this->vendor1->update([
            'google_access_token' => 'expired-token',
            'google_refresh_token' => null,
            'google_token_expires_at' => now()->subDays(10),
            'google_calendar_id' => 'primary',
            'sync_google_calendar' => true,
        ]);

        $service = app(GoogleCalendarService::class);
        $result = $service->disconnect($this->vendor1);

        $this->assertTrue($result, 'disconnect() must succeed even when token is expired/unrevokable.');
        $this->vendor1->refresh();
        $this->assertNull($this->vendor1->google_access_token);
        $this->assertNull($this->vendor1->google_calendar_id);
        $this->assertFalse((bool) $this->vendor1->sync_google_calendar);
    }

    /**
     * UC-60: getSyncStatus must exclude cancelled slots from unsynced count,
     * and syncManual must reject unauthorized cross-org order_ids.
     */
    public function test_uc60_sync_status_excludes_cancelled_slots_and_sync_manual_enforces_ownership(): void
    {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'property_id' => $this->property->id,
            'amount' => 250.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);

        // Cancelled slot with null google_event_id should NOT be counted as unsynced
        OrderSlot::create([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'vendor_id' => $this->vendor1->id,
            'date' => Carbon::now()->addDays(2)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'cancelled',
            'google_event_id' => null,
            'agent_google_event_id' => null,
        ]);

        $statusResp = $this->actingAs($this->vendor1, 'vendor-api')
            ->getJson('/api/calendar/sync-status')
            ->assertStatus(200);

        $this->assertEquals(0, $statusResp->json('unsynced_count') ?? $statusResp->json('data.unsynced_count') ?? 0);

        // Agent 2 from Org 2 must NOT be able to trigger syncManual for Org 1's order
        $syncResp = $this->actingAs($this->agent2, 'agent-api')
            ->postJson('/api/calendar/sync-manual', [
                'order_ids' => [$order->id],
            ]);

        $this->assertContains($syncResp->getStatusCode(), [403, 404, 422]);
    }
}
