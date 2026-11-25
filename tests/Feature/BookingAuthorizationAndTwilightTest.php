<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SubAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBreak;
use App\Models\VendorWorkHour;
use App\Services\GoogleCalendarService;
use App\Services\TwilightCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Automated Backend Verification Suite — Part 4:
 * Twilight Window Calculation & DST, Multi-Tenant Isolation, OAuth Token Hiding,
 * and Admin vs. Agent vs. SubAccount vs. Vendor Authorization Matrix.
 *
 * Maps directly to Use Cases: UC-07, UC-10, UC-61, UC-62, UC-64, UC-65.
 *
 * Run with:
 *   php artisan test --filter=BookingAuthorizationAndTwilightTest
 */
class BookingAuthorizationAndTwilightTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org1;
    protected Organization $org2;
    protected User $admin1;
    protected Agent $agent1;
    protected Agent $agent2;
    protected SubAccount $subAccount1;
    protected Vendor $vendor1;
    protected Vendor $vendor2;
    protected Property $property1;
    protected Service $service1;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();

        $this->org1 = Organization::create([
            'name' => 'Auth Org 1',
            'slug' => 'auth-org-1-' . Str::random(5),
        ]);
        $this->org2 = Organization::create([
            'name' => 'Auth Org 2',
            'slug' => 'auth-org-2-' . Str::random(5),
        ]);

        $this->admin1 = User::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Admin',
            'last_name' => 'One',
            'email' => 'admin1@auth.test',
            'password' => bcrypt('password'),
        ]);

        $this->agent1 = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Agent',
            'last_name' => 'One',
            'email' => 'agent1@auth.test',
            'password' => bcrypt('password'),
            'status' => true,
            'google_access_token' => 'secret-agent-access-token',
            'google_refresh_token' => 'secret-agent-refresh-token',
        ]);

        $this->agent2 = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Agent',
            'last_name' => 'Two',
            'email' => 'agent2@auth.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $this->subAccount1 = SubAccount::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'first_name' => 'Sub',
            'last_name' => 'One',
            'primary_email' => 'sub1@auth.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $this->vendor1 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Vendor',
            'last_name' => 'One',
            'email' => 'v1@auth.test',
            'password' => bcrypt('password'),
            'status' => true,
            'google_access_token' => 'secret-vendor-access-token',
            'google_refresh_token' => 'secret-vendor-refresh-token',
        ]);

        $this->vendor2 = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'first_name' => 'Vendor',
            'last_name' => 'Two',
            'email' => 'v2@auth.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);

        $category = ServiceCategory::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'title' => 'Media',
            'type' => 'service',
        ]);

        $this->service1 = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'category_id' => $category->id,
            'name' => 'Standard Photo',
            'status' => true,
        ]);

        $this->property1 = Property::create([
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'address' => '100 King St W',
            'city' => 'Toronto',
            'province' => 'ON',
            'country' => 'Canada',
        ]);
    }

    /**
     * UC-61: TwilightCalculatorService::isVendorAvailableForTwilight() must match day-of-week
     * ('mon'..'sun' via format('D'), NOT format('ddd')).
     */
    public function test_uc61_twilight_vendor_availability_checks_correct_day_of_week(): void
    {
        // Wednesday 2026-06-17
        $wednesday = '2026-06-17';

        VendorWorkHour::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $this->vendor1->id,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'timezone' => 'America/Vancouver',
            'work_days' => [
                ['day' => 'wed', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => false, 'is_twilight' => true],
                ['day' => 'thu', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => false, 'is_twilight' => false],
            ],
        ]);

        $twilightService = app(TwilightCalculatorService::class);

        $this->assertTrue(
            $twilightService->isVendorAvailableForTwilight($this->vendor1->fresh('workHours'), $wednesday),
            'Vendor with wed is_twilight=true MUST be available for twilight on Wednesday.'
        );
        $this->assertFalse(
            $twilightService->isVendorAvailableForTwilight($this->vendor1->fresh('workHours'), '2026-06-18'),
            'Vendor with thu is_twilight=false MUST NOT be available for twilight on Thursday.'
        );
    }

    /**
     * UC-62: Twilight sunset calculation for Toronto (ON) must be ~20:55-21:05 EDT in mid-June
     * (NOT ~00:20 AM from Vancouver coordinates), and DST spring-forward Sunday must be accurate.
     */
    public function test_uc62_twilight_sunset_accurate_for_toronto_and_dst_transition(): void
    {
        // 1. Endpoint GET /twilight-window for ON without explicit lat/lng
        $resp = $this->getJson('/api/twilight-window?date=2026-06-21&province=ON')
            ->assertStatus(200);

        $sunset = $resp->json('data.sunset') ?? $resp->json('sunset');
        $this->assertNotNull($sunset);
        $sunsetHour = (int) explode(':', $sunset)[0];
        $this->assertGreaterThanOrEqual(20, $sunsetHour, "Toronto June sunset ({$sunset}) must be around 21:00, not midnight/early morning.");
        $this->assertLessThanOrEqual(21, $sunsetHour, "Toronto June sunset ({$sunset}) must be around 21:00.");

        // 2. DST spring-forward Sunday in Vancouver: 2026-03-08 (clocks spring forward at 02:00 PST -> 03:00 PDT)
        // Actual Vancouver sunset on 2026-03-08 is ~19:07 PDT (NOT ~18:07 PST from previous-day UTC wrap)
        $twilightService = app(TwilightCalculatorService::class);
        $window = $twilightService->calculateSunsetWindow('2026-03-08', 49.2827, -123.1207, 'America/Vancouver');
        $dstSunsetHour = (int) explode(':', $window['sunset'])[0];
        $this->assertEquals(19, $dstSunsetHour, "Vancouver DST spring-forward Sunday sunset ({$window['sunset']}) should be ~19:07 PDT, not 18:xx.");
    }

    /**
     * UC-64: (a) GET /order-slots must ONLY return slots belonging to the caller's organization.
     *        (b) Vendor and Agent JSON serialization must NOT leak OAuth tokens.
     */
    public function test_uc64_order_slots_org_isolation_and_oauth_tokens_hidden(): void
    {
        // Create an order + slot in Org 2
        $otherAgent = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org2->id,
            'first_name' => 'Other',
            'last_name' => 'Agent',
            'email' => 'other@org2.test',
            'password' => bcrypt('password'),
        ]);
        $otherOrder = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org2->id,
            'agent_id' => $otherAgent->id,
            'amount' => 100.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $otherOrgSlot = OrderSlot::create([
            'order_id' => $otherOrder->id,
            'service_id' => $this->service1->id,
            'vendor_id' => $this->vendor1->id,
            'date' => Carbon::now()->addDays(3)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        // Admin in Org 1 calls GET /order-slots -> must NOT see Org 2's slot
        $slotsResp = $this->actingAs($this->admin1, 'api')
            ->getJson('/api/order-slots')
            ->assertStatus(200);
        $returnedUuids = collect($slotsResp->json('data') ?? $slotsResp->json())->pluck('uuid')->all();
        $this->assertNotContains($otherOrgSlot->uuid, $returnedUuids, 'Cross-organization OrderSlot leaked in GET /order-slots!');

        // OAuth tokens must be hidden on Vendor & Agent serialization
        $vendorArray = $this->vendor1->toArray();
        $agentArray = $this->agent1->toArray();
        $this->assertArrayNotHasKey('google_access_token', $vendorArray, 'Vendor leaks plaintext google_access_token!');
        $this->assertArrayNotHasKey('google_refresh_token', $vendorArray, 'Vendor leaks plaintext google_refresh_token!');
        $this->assertArrayNotHasKey('google_access_token', $agentArray, 'Agent leaks plaintext google_access_token!');
        $this->assertArrayNotHasKey('google_refresh_token', $agentArray, 'Agent leaks plaintext google_refresh_token!');
    }

    /**
     * UC-07 & UC-10: SubAccount / other Agent must NOT be able to cancel or modify another Agent's order.
     */
    public function test_uc10_subaccount_and_other_agent_cannot_cancel_or_modify_unowned_order(): void
    {
        $agent2Order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent2->id,
            'property_id' => $this->property1->id,
            'amount' => 300.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);

        // SubAccount belonging to Agent 1 tries to cancel Agent 2's order -> 403
        $this->actingAs($this->subAccount1, 'subaccount-api')
            ->postJson("/api/orders/{$agent2Order->uuid}/cancel", ['reason' => 'Unauthorized'])
            ->assertStatus(403);

        // Agent 1 tries to mutate Agent 2's order payment_status -> 403
        $this->actingAs($this->agent1, 'agent-api')
            ->putJson("/api/orders/{$agent2Order->uuid}", [
                'payment_status' => 'PAID',
            ])
            ->assertStatus(403);
    }

    /**
     * UC-65: Role authorization matrix across privileged order/vendor/calendar endpoints,
     * plus Agent read access to vendor availability endpoints by UUID.
     */
    public function test_uc65_role_authorization_matrix_and_agent_availability_access(): void
    {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $this->org1->id,
            'agent_id' => $this->agent1->id,
            'property_id' => $this->property1->id,
            'amount' => 300.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        $orderService = OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $order->id,
            'service_id' => $this->service1->id,
            'amount' => 300.00,
            'vendor_id' => $this->vendor1->uuid,
        ]);

        // 1. Agent must NOT unlock their own media_access
        $this->actingAs($this->agent1, 'agent-api')
            ->patchJson('/api/orders/media-access/update', [
                'order_service_id' => $orderService->uuid,
                'media_access' => true,
            ])
            ->assertStatus(403);

        // 2. Agent must NOT hard-delete orders
        $this->actingAs($this->agent1, 'agent-api')
            ->deleteJson("/api/orders/{$order->uuid}")
            ->assertStatus(403);

        // 3. Vendor 1 must NOT add a break to Vendor 2 (IDOR)
        $this->actingAs($this->vendor1, 'vendor-api')
            ->postJson('/api/vendor-breaks/add', [
                'vendor_id' => $this->vendor2->uuid,
                'title' => 'Malicious Break',
                'date' => Carbon::now()->addDays(4)->toDateString(),
                'start_date' => Carbon::now()->addDays(4)->toDateString(),
                'end_date' => Carbon::now()->addDays(4)->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ])
            ->assertStatus(403);

        // 4. Agent SHOULD be allowed to query vendor busy-slots by UUID (currently 401 + integer ID bug)
        $mockGcal = Mockery::mock(GoogleCalendarService::class);
        $mockGcal->shouldReceive('getVendorBusySlots')->andReturn([]);
        $this->app->instance(GoogleCalendarService::class, $mockGcal);

        $this->actingAs($this->agent1, 'agent-api')
            ->getJson("/api/vendors/{$this->vendor1->uuid}/busy-slots?start_date=2026-06-01&end_date=2026-06-07")
            ->assertStatus(200);
    }
}
