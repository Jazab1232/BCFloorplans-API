<?php

namespace Tests\Unit;

use App\Models\GlobalTourSetting;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\AreaPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AreaPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Area Pricing Test Organization',
            'slug' => 'area-pricing-' . uniqid(),
            'province' => 'BC',
        ]);
    }

    public function test_enabled_allowance_charges_only_excess_zero_price_other_areas(): void
    {
        $this->setPortalSettings(true, 1000, 0.10);

        $metrics = app(AreaPricingService::class)->calculateAreaMetrics([
            ['type' => 'Finished', 'footage' => 2000, 'custom_title' => 'Main Level'],
            ['type' => 'Other', 'footage' => 500, 'custom_title' => 'Deck'],
            ['type' => 'Other', 'footage' => 1000, 'custom_title' => 'Garage'],
        ], $this->organization->id);

        $this->assertSame(1000, $metrics['free_allowance_used']);
        $this->assertSame(500, $metrics['excess_other_footage']);
        $this->assertSame(50.0, $metrics['excess_other_charge']);
        $this->assertSame(2000, $metrics['total_billable_sqft']);
    }

    public function test_disabled_allowance_makes_all_zero_price_other_areas_billable_in_service_sqft(): void
    {
        $this->setPortalSettings(false, 1000, 0.10);

        $metrics = app(AreaPricingService::class)->calculateAreaMetrics([
            ['type' => 'Finished', 'footage' => 2000, 'custom_title' => 'Main Level'],
            ['type' => 'Other', 'footage' => 1400, 'custom_title' => 'Deck'],
        ], $this->organization->id);

        $this->assertSame(0, $metrics['free_allowance_used']);
        $this->assertSame(1400, $metrics['excess_other_footage']);
        $this->assertSame(0, $metrics['excess_other_charge']);
        $this->assertSame(3400, $metrics['total_billable_sqft']);
    }

    public function test_fixed_price_other_area_is_excluded_from_allowance_and_charged_once(): void
    {
        $this->setPortalSettings(true, 1000, 0.10);
        GlobalTourSetting::create([
            'organization_id' => $this->organization->id,
            'area' => 'Driveway',
            'type' => 'Other Area',
            'charge' => 40,
            'status' => true,
        ]);

        $metrics = app(AreaPricingService::class)->calculateAreaMetrics([
            ['type' => 'Other', 'footage' => 500, 'custom_title' => 'Deck'],
            ['type' => 'Other', 'footage' => 500, 'custom_title' => 'Garage'],
            ['type' => 'Other', 'footage' => 400, 'custom_title' => 'Driveway'],
        ], $this->organization->id);

        $this->assertSame(1000, $metrics['zero_charge_other_footage']);
        $this->assertSame(0, $metrics['excess_other_footage']);
        $this->assertSame(40.0, $metrics['custom_other_charges']);
    }

    private function setPortalSettings(bool $enabled, int $allowance, float $rate): void
    {
        Setting::create([
            'org_id' => $this->organization->uuid,
            'key' => 'portal_settings',
            'value' => [
                'other_areas_enable_allowance' => $enabled,
                'other_areas_free_allowance' => $allowance,
                'other_areas_rate_per_sq_ft' => $rate,
            ],
        ]);

        Cache::flush();
    }
}