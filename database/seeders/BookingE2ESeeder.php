<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Order;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\ProductOption;
use App\Models\Property;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use App\Models\VendorBreak;
use App\Models\VendorService;
use App\Models\VendorSetting;
use App\Models\VendorWorkHour;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingE2ESeeder extends Seeder
{
    public function run(): void
    {
        $password = bcrypt('Password123!');
        $tz = 'America/Vancouver';
        $nowVan = Carbon::now($tz);
        $dateT5 = $nowVan->copy()->addDays(5)->toDateString();
        $datePast = $nowVan->copy()->subDays(5)->toDateString();
        $dateT10 = $nowVan->copy()->addDays(10)->toDateString();
        $dateT12 = $nowVan->copy()->addDays(12)->toDateString();

        // Ensure Passport personal access clients exist for users, agents & vendors so /api/login works after migrate:fresh
        if (DB::table('oauth_clients')->count() === 0) {
            foreach (['users', 'agents', 'vendors'] as $provider) {
                Artisan::call('passport:client', [
                    '--personal' => true,
                    '--provider' => $provider,
                    '--name' => "BCF E2E {$provider} Personal Client",
                    '--no-interaction' => true,
                ]);
            }
        }

        // 1. Organization + Portal Settings
        $org = Organization::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'BCF E2E Test Org',
            'slug' => 'bcf-e2e-test-org',
            'is_active' => true,
        ]);

        // 2. Roles, Permissions, Admin & Agent
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $agentRole = Role::firstOrCreate(['name' => 'agent']);

        $permissionNames = [
            'Access Billing',
            'Receive Notifications',
            'Create Orders',
            'Edit Orders',
            'Book Appointments',
            'Create Vendor',
            'Update Vendor',
            'Create Agent',
            'Create Admin',
            'View Admin',
            'Create Services',
            'View Orders',
            'View Appointments',
            'Edit Appointments',
            'Set Discounts',
            'Create Listing',
            'View Listing',
            'View Services',
            'Create Tour Settings',
            'Access Vendor Billing',
            'View Vendor',
            'View Agent',
            'View All Orders',
            'View Only Orders For Co-Agent',
            'Create Sub-Accounts',
            'View All Appointments',
            'View Only Appointments For Co-Agent',
            'Print Requests',
        ];

        $permissionIds = [];
        foreach ($permissionNames as $permName) {
            $permissionIds[] = Permission::firstOrCreate(['name' => $permName])->id;
        }
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);

        $admin = User::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'E2E',
            'last_name' => 'Admin',
            'email' => 'admin@e2e.test',
            'password' => $password,
        ]);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $admin->permissions()->syncWithoutDetaching($permissionIds);

        app(SettingsService::class)->set($org->uuid, 'portal_settings', [
            'show_org_details_on_empty_schedule' => false,
            'disable_next_day_booking' => false,
            'booking_cutoff_time' => '17:00',
            'allow_print_request' => true,
            'allow_booking_through_lunch' => true,
        ], $admin->uuid);

        $defaultTheme = [
            'pageBg' => '#FFFFFF',
            'pageText' => '#6D6D6D',
            'pageTabColor' => '#4290E9',
            'activeColor' => '#4290E9',
            'sidebarBg' => '#F3F4F6',
            'sidebarText' => '#4B5563',
            'sidebarHoverBg' => '#E5E7EB',
            'sidebarHoverText' => '#6D6D6D',
        ];
        app(SettingsService::class)->set($org->uuid, 'white_label_styles', [
            'admin' => $defaultTheme,
            'agent' => $defaultTheme,
            'vendor' => $defaultTheme,
            'co_agent' => $defaultTheme,
        ], $admin->uuid);

        $agent = Agent::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'role_id' => $agentRole->id,
            'first_name' => 'E2E',
            'last_name' => 'Agent',
            'email' => 'agent@e2e.test',
            'password' => $password,
            'status' => true,
        ]);

        // 3. Vendors
        $vancouverPolygon = [
            ['lat' => 49.3000, 'lng' => -123.2200],
            ['lat' => 49.3000, 'lng' => -123.0200],
            ['lat' => 49.2000, 'lng' => -123.0200],
            ['lat' => 49.2000, 'lng' => -123.2200],
        ];

        $surreyPolygon = [
            ['lat' => 49.2200, 'lng' => -122.9000],
            ['lat' => 49.2200, 'lng' => -122.7000],
            ['lat' => 49.0800, 'lng' => -122.7000],
            ['lat' => 49.0800, 'lng' => -122.9000],
        ];

        $victor = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'Victor',
            'last_name' => 'Alpha',
            'email' => 'vendora@e2e.test',
            'primary_phone' => '604-555-0101',
            'password' => $password,
            'status' => true,
            'coordinates' => json_encode($vancouverPolygon),
        ]);

        VendorAddress::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $victor->id,
            'type' => 'start_location',
            'address_line_1' => '555 W Hastings St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'Canada',
        ]);

        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $victor->id,
            'enable_service_area' => true,
            'force_service_area' => true,
            'is_kilometers' => true,
        ]);

        $this->seedWorkHours($victor, $tz, satSunOff: true);

        $vanessa = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'Vanessa',
            'last_name' => 'Beta',
            'email' => 'vendorb@e2e.test',
            'primary_phone' => '604-555-0102',
            'password' => $password,
            'status' => true,
            'coordinates' => json_encode($surreyPolygon),
        ]);

        VendorAddress::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vanessa->id,
            'type' => 'start_location',
            'address_line_1' => '10250 152 St',
            'city' => 'Surrey',
            'province' => 'BC',
            'country' => 'Canada',
        ]);

        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vanessa->id,
            'enable_service_area' => true,
            'force_service_area' => false,
            'is_kilometers' => true,
        ]);

        $this->seedWorkHours($vanessa, $tz, satSunOff: false);

        $charlie = Vendor::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'first_name' => 'Charlie',
            'last_name' => 'NoAddress',
            'email' => 'vendorc@e2e.test',
            'primary_phone' => '604-555-0103',
            'password' => $password,
            'status' => true,
        ]);

        VendorSetting::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $charlie->id,
            'enable_service_area' => false,
            'force_service_area' => false,
            'is_kilometers' => true,
        ]);

        $this->seedWorkHours($charlie, $tz, satSunOff: false);

        // 4. Categories & Services
        $photoCat = ServiceCategory::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Photography',
            'type' => ['area'],
        ]);

        $twilightCat = ServiceCategory::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Twilight Photos',
            'type' => ['fixed'],
        ]);

        $svcPhotos = Service::create([
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

        $svcNoTravel = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'category_id' => $photoCat->id,
            'name' => 'E2E Virtual Staging (No Travel)',
            'status' => true,
            'is_travel_required' => false,
            'base_duration_mins' => 30,
            'base_sq_ft' => 2000,
            'increment_duration_mins' => 15,
            'increment_sq_ft' => 1000,
        ]);

        $svcTwilight = Service::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'category_id' => $twilightCat->id,
            'name' => 'E2E Twilight Shoot',
            'status' => true,
            'is_travel_required' => true,
            'base_duration_mins' => 60,
            'base_sq_ft' => 2000,
            'increment_duration_mins' => 30,
            'increment_sq_ft' => 1000,
        ]);

        $services = [$svcPhotos, $svcFloorPlan, $svcNoTravel, $svcTwilight];
        foreach ($services as $svc) {
            ProductOption::create([
                'service_id' => $svc->id,
                'title' => 'Standard',
                'quantity' => 1,
                'sq_ft_range' => '0-10000',
                'amount' => 250.00,
                'min_price' => 250.00,
                'service_duration' => $svc->base_duration_mins,
                'base_duration_mins' => $svc->base_duration_mins,
                'base_sq_ft' => $svc->base_sq_ft,
                'increment_duration_mins' => $svc->increment_duration_mins,
                'increment_sq_ft' => $svc->increment_sq_ft,
            ]);

            foreach ([$victor, $vanessa, $charlie] as $vendor) {
                VendorService::create([
                    'vendor_id' => $vendor->id,
                    'service_id' => $svc->id,
                    'status' => true,
                    'pay_type' => 'fixed',
                    'vendor_price' => 100.00,
                ]);
            }
        }

        // 5. Properties
        $propVan = new Property([
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'address' => '100 Robson St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'postal_code' => 'V6B 2A7',
            'country' => 'Canada',
            'square_footage' => 2000,
        ]);
        $propVan->user_id = $admin->id;
        $propVan->save();

        $propLarge = new Property([
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'address' => '200 Granville St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'postal_code' => 'V6C 1S4',
            'country' => 'Canada',
            'square_footage' => 3500,
        ]);
        $propLarge->user_id = $admin->id;
        $propLarge->save();

        $propClean = new Property([
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'address' => '300 Burrard St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'postal_code' => 'V6C 2G8',
            'country' => 'Canada',
            'square_footage' => 2000,
        ]);
        $propClean->user_id = $admin->id;
        $propClean->save();

        // 6. Orders & Slots
        // Active booking on T+5 (10:00-11:00)
        $this->createOrderWithSlot(
            $org, $agent, $propVan, $svcPhotos, $victor,
            $dateT5, '10:00:00', '11:00:00', 'Processing', 'booked'
        );

        // Cancelled booking on T+5 (14:00-15:00)
        $this->createOrderWithSlot(
            $org, $agent, $propVan, $svcPhotos, $victor,
            $dateT5, '14:00:00', '15:00:00', 'Cancelled', 'cancelled'
        );

        // Past order on T-5 (10:00-11:00)
        $this->createOrderWithSlot(
            $org, $agent, $propVan, $svcPhotos, $victor,
            $datePast, '10:00:00', '11:00:00', 'Completed', 'booked'
        );

        // Later-today slot (now + 3h in Vancouver, capped before 22:00)
        $laterStart = $nowVan->copy()->addHours(3)->minute(0)->second(0);
        if ($laterStart->hour >= 22) {
            $laterStart = $nowVan->copy()->addDay()->hour(10)->minute(0)->second(0);
        }
        $laterEnd = $laterStart->copy()->addHour();
        $this->createOrderWithSlot(
            $org, $agent, $propVan, $svcPhotos, $victor,
            $laterStart->toDateString(),
            $laterStart->format('H:i:s'),
            $laterEnd->format('H:i:s'),
            'Processing',
            'booked'
        );

        // 7. Breaks & Multi-day Time-Off
        VendorBreak::create([
            'vendor_id' => $victor->id,
            'title' => 'E2E One-Time Break',
            'date' => $dateT5,
            'start_date' => $dateT5,
            'end_date' => $dateT5,
            'start_time' => '15:30',
            'end_time' => '16:30',
            'address' => 'Vancouver, BC',
        ]);

        VendorBreak::create([
            'vendor_id' => $victor->id,
            'title' => 'E2E Multi-Day Time Off',
            'date' => $dateT10,
            'start_date' => $dateT10,
            'end_date' => $dateT12,
            'start_time' => '00:00',
            'end_time' => '23:59',
            'address' => 'Vancouver, BC',
        ]);
    }

    protected function seedWorkHours(Vendor $vendor, string $timezone, bool $satSunOff): void
    {
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $workDays = array_map(fn ($day) => [
            'day' => $day,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_off' => $satSunOff && in_array($day, ['sat', 'sun'], true),
            'is_twilight' => true,
        ], $days);

        VendorWorkHour::create([
            'uuid' => Str::uuid()->toString(),
            'vendor_id' => $vendor->id,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'work_days' => $workDays,
            'repeat_weekly' => true,
            'break_start' => '12:00',
            'break_end' => '13:00',
            'commute_minutes' => 15,
            'timezone' => $timezone,
        ]);
    }

    protected function createOrderWithSlot(
        Organization $org,
        Agent $agent,
        Property $property,
        Service $service,
        Vendor $vendor,
        string $date,
        string $startTime,
        string $endTime,
        string $orderStatus,
        string $slotStatus
    ): Order {
        $order = Order::create([
            'uuid' => Str::uuid()->toString(),
            'organization_id' => $org->id,
            'agent_id' => $agent->id,
            'property_id' => $property->id,
            'property_address' => "{$property->address}, {$property->city}, {$property->province}, {$property->country}",
            'amount' => 250.00,
            'order_status' => $orderStatus,
            'payment_status' => 'UNPAID',
        ]);

        OrderService::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $order->id,
            'service_id' => $service->id,
            'vendor_id' => $vendor->uuid,
            'amount' => 250.00,
            'payment_status' => 'UNPAID',
            'status' => $orderStatus,
        ]);

        OrderSlot::create([
            'uuid' => Str::uuid()->toString(),
            'order_id' => $order->id,
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'address' => "{$property->address}, {$property->city}, {$property->province}, {$property->country}",
            'status' => $slotStatus,
        ]);

        return $order;
    }
}
