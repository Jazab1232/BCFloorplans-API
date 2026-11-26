<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\Organization;
use App\Models\ProductOption;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use App\Models\VendorBreak;
use App\Models\VendorService;
use App\Models\VendorSetting;
use App\Models\VendorWorkHour;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Deterministic E2E Test Data Seeder for the 32 Frontend UI Tests (Playwright / Puppeteer).
 *
 * Usage on a disposable test database:
 *   php artisan migrate:fresh --seeder=BookingE2ESeeder
 *
 * Credentials seeded:
 *   Admin:  admin@e2e.test   / Password123!
 *   Agent:  agent@e2e.test   / Password123!
 *   Vendor: vendora@e2e.test / Password123!
 */
class BookingE2ESeeder extends Seeder
{
    public function run(): void
    {
        $tz = 'America/Vancouver';
        $today = Carbon::now($tz);
        $targetDate = $today->copy()->addDays(5)->toDateString();      // T+5 days
        $pastDate = $today->copy()->subDays(5)->toDateString();        // T-5 days (for UI-12 Admin past order)
        $multiDayStart = $today->copy()->addDays(10)->toDateString();  // T+10..T+12 (for UI-26 multi-day break)
        $multiDayEnd = $today->copy()->addDays(12)->toDateString();

        // 1. Organization
        $org = Organization::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'BCF E2E Test Org',
            'slug' => 'bcf-e2e-org',
        ]);

        // Portal Settings (enable allow_booking_through_lunch for UI-11)
        Setting::create([
            'uuid' => Str::uuid()->toString(),
            'org_id' => $org->uuid,
            'key' => 'portal_settings',
            'value' => [
                'cancellation_threshold_hours' => 24,
                'cancellation_fee_percentage' => 25.00,
                'allow_cancel_after_threshold' => true,
                'allow_booking_through_lunch' => true,
                'show_org_details_on_empty_schedule' => false,
                'disable_next_day_booking' => false,
                'booking_cutoff_time' => '17:00',
            ],
        ]);

        // 2. Admin & Agent Accounts
        $password = Hash::make('Password123!');

        User::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'E2E',
            'last_name' => 'Admin',
            'email' => 'admin@e2e.test',
            'password' => $password,
        ]);

        $agent = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'E2E',
            'last_name' => 'Agent',
            'email' => 'agent@e2e.test',
            'password' => $password,
            'status' => true,
        ]);

        // 3. Vendors
        // Downtown Vancouver polygon (covers 100 Robson St, Vancouver)
        $vancouverPolygon = json_encode([
            ['lat' => 49.3200, 'lng' => -123.2000],
            ['lat' => 49.3200, 'lng' => -123.0200],
            ['lat' => 49.2000, 'lng' => -123.0200],
            ['lat' => 49.2000, 'lng' => -123.2000],
        ]);

        // Surrey-only polygon (excludes Downtown Vancouver -> out-of-area test UI-06 / UI-07)
        $surreyPolygon = json_encode([
            ['lat' => 49.2000, 'lng' => -122.9000],
            ['lat' => 49.2000, 'lng' => -122.7000],
            ['lat' => 49.0500, 'lng' => -122.7000],
            ['lat' => 49.0500, 'lng' => -122.9000],
        ]);

        // Vendor A: Primary in-area Vancouver vendor (Mon-Fri 09:00-17:00, lunch 12:00-13:00, Sat/Sun OFF)
        $vendorA = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'Victor',
            'last_name' => 'Alpha',
            'email' => 'vendora@e2e.test',
            'password' => $password,
            'status' => true,
            'coordinates' => $vancouverPolygon,
            'sync_google_calendar' => true,
        ]);

        // Vendor B: Out-of-area vendor (Surrey polygon, force_service_area = false -> overridable by Admin)
        $vendorB = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'Vanessa',
            'last_name' => 'Beta',
            'email' => 'vendorb@e2e.test',
            'password' => $password,
            'status' => true,
            'coordinates' => $surreyPolygon,
            'sync_google_calendar' => false,
        ]);

        // Vendor C: In-area vendor with NO start_location configured (for UI-22)
        $vendorC = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'Charlie',
            'last_name' => 'NoAddress',
            'email' => 'vendorc@e2e.test',
            'password' => $password,
            'status' => true,
            'coordinates' => $vancouverPolygon,
            'sync_google_calendar' => false,
        ]);

        // Vendor Settings
        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendorA->id,
            'payment_per_km' => 1.25,
            'enable_service_area' => true,
            'force_service_area' => false,
            'is_kilometers' => true,
            'next_booking_slot_only' => false,
        ]);

        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendorB->id,
            'payment_per_km' => 1.50,
            'enable_service_area' => true,
            'force_service_area' => false, // Hidden for Agent, overridable by Admin
            'is_kilometers' => true,
            'next_booking_slot_only' => false,
        ]);

        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendorC->id,
            'payment_per_km' => 1.00,
            'enable_service_area' => true,
            'force_service_area' => false,
            'is_kilometers' => true,
            'next_booking_slot_only' => false,
        ]);

        // Vendor Addresses (Vendor C intentionally omitted for UI-22!)
        VendorAddress::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendorA->id,
            'type' => 'start_location',
            'address_line_1' => '800 W Georgia St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'Canada',
        ]);

        VendorAddress::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendorB->id,
            'type' => 'start_location',
            'address_line_1' => '10153 King George Blvd',
            'city' => 'Surrey',
            'province' => 'BC',
            'country' => 'Canada',
        ]);

        // Vendor Work Hours (Mon-Fri 09:00-17:00, lunch 12:00-13:00, Sat/Sun is_off = true for UI-28)
        $workDays = [
            ['day' => 'mon', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => false, 'is_twilight' => true],
            ['day' => 'tue', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => false, 'is_twilight' => true],
            ['day' => 'wed', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => false, 'is_twilight' => true],
            ['day' => 'thu', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => false, 'is_twilight' => true],
            ['day' => 'fri', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => false, 'is_twilight' => true],
            ['day' => 'sat', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => true,  'is_twilight' => false],
            ['day' => 'sun', 'start_time' => '09:00', 'end_time' => '17:00', 'is_off' => true,  'is_twilight' => false],
        ];

        foreach ([$vendorA, $vendorB, $vendorC] as $v) {
            VendorWorkHour::create([
                'uuid' => Str::uuid()->toString(),
                'vendor_id' => $v->id,
                'start_time' => '09:00',
                'end_time' => '17:00',
                'work_days' => $workDays,
                'repeat_weekly' => true,
                'break_start' => '12:00',
                'break_end' => '13:00',
                'commute_minutes' => 15,
                'timezone' => $tz,
            ]);
        }

        // 4. Service Categories & Services
        $photoCat = ServiceCategory::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'title' => 'Photography',
            'type' => 'service',
        ]);

        // Standard 60-min service (travel required)
        $svcPhoto = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'category_id' => $photoCat->id,
            'name' => 'E2E HDR Photos',
            'status' => true,
            'is_travel_required' => true,
            'base_duration_mins' => 60,
            'base_sq_ft' => 2000,
            'increment_duration_mins' => 30,
            'increment_sq_ft' => 1000,
        ]);

        // Dynamic SqFt service (for UI-04: 45m base up to 1500sqft, +30m per 1000sqft -> 3500sqft = 105m)
        $svcFloorPlan = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'category_id' => $photoCat->id,
            'name' => 'E2E Floor Plan',
            'status' => true,
            'is_travel_required' => true,
            'base_duration_mins' => 45,
            'base_sq_ft' => 1500,
            'increment_duration_mins' => 30,
            'increment_sq_ft' => 1000,
        ]);

        // Non-travel service (for UI-20: is_travel_required = false)
        $svcVirtual = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'category_id' => $photoCat->id,
            'name' => 'E2E Virtual Staging (No Travel)',
            'status' => true,
            'is_travel_required' => false,
            'base_duration_mins' => 30,
        ]);

        // Twilight service
        $svcTwilight = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'category_id' => $photoCat->id,
            'name' => 'E2E Twilight Shoot',
            'type' => 'twilight',
            'status' => true,
            'is_travel_required' => true,
            'base_duration_mins' => 60,
        ]);

        // Product Options
        foreach ([$svcPhoto, $svcFloorPlan, $svcVirtual, $svcTwilight] as $svc) {
            ProductOption::create([
                'uuid' => Str::uuid()->toString(),
                'service_id' => $svc->id,
                'title' => 'Standard Package',
                'amount' => 200.00,
                'service_duration' => $svc->base_duration_mins,
            ]);

            foreach ([$vendorA, $vendorB, $vendorC] as $v) {
                VendorService::create([
                    'uuid' => Str::uuid()->toString(),
                    'vendor_id' => $v->id,
                    'service_id' => $svc->id,
                    'status' => true,
                    'vendor_price' => 100.00,
                ]);
            }
        }

        // 5. Properties
        $propVancouver = Property::create([
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'address' => '100 Robson St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'postal_code' => 'V6B 2A7',
            'country' => 'Canada',
            'square_footage' => 2000,
            'latitude' => 49.2775,
            'longitude' => -123.1128,
        ]);

        $propBurnaby = Property::create([
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'address' => '100 Robson St', // Same street prefix, different city (UI-19)
            'city' => 'Burnaby',
            'province' => 'BC',
            'postal_code' => 'V5H 2A1',
            'country' => 'Canada',
            'square_footage' => 2000,
            'latitude' => 49.2276,
            'longitude' => -123.0076,
        ]);

        // 6. Seeded Orders & Slots
        // (a) Active booking on T+5 at 10:00-11:00 for Vendor A (UI-01, UI-05, UI-08, UI-16, UI-17, UI-20)
        $activeOrder = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'property_id' => $propVancouver->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $activeOrder->id,
            'service_id' => $svcPhoto->id,
            'amount' => 200.00,
            'vendor_id' => $vendorA->uuid,
            'status' => 'active',
        ]);
        OrderSlot::create([
            'order_id' => $activeOrder->id,
            'service_id' => $svcPhoto->id,
            'vendor_id' => $vendorA->id,
            'date' => $targetDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'address' => '100 Robson St, Vancouver, BC',
            'status' => 'booked',
        ]);

        // (b) Cancelled booking on T+5 at 14:00-15:00 for Vendor A (UI-16)
        $cancelledOrder = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'property_id' => $propBurnaby->id,
            'amount' => 200.00,
            'order_status' => 'Cancelled',
            'payment_status' => 'UNPAID',
        ]);
        OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $cancelledOrder->id,
            'service_id' => $svcPhoto->id,
            'amount' => 200.00,
            'vendor_id' => $vendorA->uuid,
            'status' => 'cancelled',
        ]);
        OrderSlot::create([
            'order_id' => $cancelledOrder->id,
            'service_id' => $svcPhoto->id,
            'vendor_id' => $vendorA->id,
            'date' => $targetDate,
            'start_time' => '14:00:00',
            'end_time' => '15:00:00',
            'address' => '100 Robson St, Burnaby, BC',
            'status' => 'cancelled',
        ]);

        // (c) Past-dated order on T-5 for Admin edit test (UI-12)
        $pastOrder = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'property_id' => $propVancouver->id,
            'amount' => 200.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
        ]);
        OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $pastOrder->id,
            'service_id' => $svcPhoto->id,
            'amount' => 200.00,
            'vendor_id' => $vendorA->uuid,
            'status' => 'active',
        ]);
        OrderSlot::create([
            'order_id' => $pastOrder->id,
            'service_id' => $svcPhoto->id,
            'vendor_id' => $vendorA->id,
            'date' => $pastDate,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'address' => '100 Robson St, Vancouver, BC',
            'status' => 'booked',
        ]);

        // (d) Today's future slot (now + 3h in Vancouver, capped at 21:00) for UI-31 (isSlotPast UTC bug)
        $laterToday = $today->copy()->addHours(3);
        if ($laterToday->toDateString() === $today->toDateString() && $laterToday->hour <= 21) {
            $todayOrder = Order::create([
                'uuid' => Str::uuid()->toString(),
                'organization_id' => $org->id,
                'agent_id' => $agent->id,
                'property_id' => $propVancouver->id,
                'amount' => 200.00,
                'order_status' => 'Processing',
                'payment_status' => 'UNPAID',
            ]);
            OrderService::create([
                'uuid' => Str::uuid()->toString(),
                'order_id' => $todayOrder->id,
                'service_id' => $svcPhoto->id,
                'amount' => 200.00,
                'vendor_id' => $vendorA->uuid,
                'status' => 'active',
            ]);
            OrderSlot::create([
                'order_id' => $todayOrder->id,
                'service_id' => $svcPhoto->id,
                'vendor_id' => $vendorA->id,
                'date' => $today->toDateString(),
                'start_time' => $laterToday->format('H:00:00'),
                'end_time' => $laterToday->copy()->addHour()->format('H:00:00'),
                'address' => '100 Robson St, Vancouver, BC',
                'status' => 'booked',
            ]);
        }

        // 7. Vendor Breaks
        // (a) One-time break on T+5 at 15:30-16:30 (UI-25: must NOT block T+6!)
        VendorBreak::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendorA->id,
            'title' => 'E2E One-Time Dentist',
            'date' => $targetDate,
            'start_date' => $targetDate,
            'end_date' => $targetDate,
            'start_time' => '15:30:00',
            'end_time' => '16:30:00',
        ]);

        // (b) Multi-day time-off on T+10..T+12 (UI-26 & UI-27)
        VendorBreak::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendorA->id,
            'title' => 'E2E Multi-Day Vacation',
            'date' => $multiDayStart,
            'start_date' => $multiDayStart,
            'end_date' => $multiDayEnd,
            'start_time' => '09:00:00',
            'end_time' => '15:00:00',
        ]);

        $this->command?->info('BookingE2ESeeder completed successfully!');
        $this->command?->table(
            ['Entity', 'Identifier / Value'],
            [
                ['Admin Login', 'admin@e2e.test / Password123!'],
                ['Agent Login', 'agent@e2e.test / Password123!'],
                ['Vendor A (Vancouver)', $vendorA->uuid],
                ['Vendor B (Surrey / Out-of-Area)', $vendorB->uuid],
                ['Vendor C (No start_location)', $vendorC->uuid],
                ['Target Date (T+5)', $targetDate],
                ['Past Order UUID (UI-12)', $pastOrder->uuid],
            ]
        );
    }
}
