<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Setting;
use App\Models\GlobalTourSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class GlobalSettingsApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $superAdmin;
    protected $orgAdmin;
    protected $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create testing organization
        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-organization-' . rand(1000, 9999),
            'contact_email' => 'contact@testorg.com',
            'contact_phone' => '123-456-7890',
            'address_line_1' => '123 Main St',
            'city' => 'Metropolis',
            'province' => 'NY',
        ]);

        // Create Super Admin user
        $this->superAdmin = User::factory()->create([
            'email' => 'todd@tojuco.com',
            'organization_id' => $this->organization->id,
        ]);

        // Create a regular Org Admin user
        $this->orgAdmin = User::factory()->create([
            'email' => 'admin@testorg.com',
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test saving Matterport expiry defaults via /settings/tour_settings
     */
    public function test_super_admin_can_save_matterport_defaults()
    {
        $payload = [
            'value' => [
                'music_enabled' => true,
                'default_song' => 'tell-me-what',
                'transition_effect' => ['kenburns'],
                'layout_option' => 'standard',
                'video_slideshow_enabled' => true,
                'letterbox_correction' => true,
                'aspect_ratio' => '16:9',
                'autoplay_enabled' => true,
                'allow_print_download' => true,
                'allow_client_upload' => true,
                'require_payment_before_download' => false,
                'enable_matterport_default_expiry' => true,
                'matterport_default_expiry_days' => 30,
            ]
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/settings/tour_settings', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('setting.value.enable_matterport_default_expiry', true)
            ->assertJsonPath('setting.value.matterport_default_expiry_days', 30);

        $this->assertDatabaseHas('settings', [
            'key' => 'tour_settings',
            'org_id' => null, // Since Super Admin saves global settings by default if no org_uuid is specified
        ]);
    }

    /**
     * Test saving tour_settings with empty default_song and default_audio_uuid
     */
    public function test_super_admin_can_save_tour_settings_with_empty_default_song()
    {
        $payload = [
            'value' => [
                'music_enabled' => true,
                'default_song' => '',
                'default_audio_uuid' => '',
                'transition_effect' => ['kenburns'],
                'layout_option' => 'standard',
                'video_slideshow_enabled' => true,
                'letterbox_correction' => true,
                'aspect_ratio' => '16:9',
                'autoplay_enabled' => true,
                'allow_print_download' => true,
                'allow_client_upload' => true,
                'require_payment_before_download' => true,
                'enable_matterport_default_expiry' => true,
                'matterport_default_expiry_days' => 120,
            ]
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/settings/tour_settings', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('setting.value.default_song', '')
            ->assertJsonPath('setting.value.default_audio_uuid', '');
    }

    /**
     * Test saving global portal settings via POST /global-settings
     */
    public function test_super_admin_can_save_global_portal_settings()
    {
        $payload = [
            'portal_settings' => [
                'show_org_details_on_empty_schedule' => true,
                'disable_next_day_booking' => true,
                'booking_cutoff_time' => '17:00',
            ]
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/global-settings', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.portal_settings.show_org_details_on_empty_schedule', true)
            ->assertJsonPath('data.portal_settings.disable_next_day_booking', true);

        $this->assertDatabaseHas('settings', [
            'key' => 'portal_settings',
            'org_id' => null,
        ]);
    }

    /**
     * Test saving organization-specific portal settings override
     */
    public function test_org_admin_can_override_portal_settings()
    {
        $payload = [
            'value' => [
                'show_org_details_on_empty_schedule' => true,
                'disable_next_day_booking' => false,
                'booking_cutoff_time' => '16:00',
            ]
        ];

        $response = $this->actingAs($this->orgAdmin, 'api')
            ->postJson('/api/settings/portal_settings', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('setting.value.booking_cutoff_time', '16:00');

        $this->assertDatabaseHas('settings', [
            'key' => 'portal_settings',
            'org_id' => $this->organization->uuid,
        ]);
    }

    /**
     * Test GET /global-settings returns portal settings
     */
    public function test_get_global_settings_includes_portal_settings()
    {
        // Setup global portal settings
        Setting::create([
            'key' => 'portal_settings',
            'value' => [
                'show_org_details_on_empty_schedule' => true,
                'disable_next_day_booking' => true,
                'booking_cutoff_time' => '17:00',
            ],
            'org_id' => null,
        ]);

        $response = $this->actingAs($this->orgAdmin, 'api')
            ->getJson('/api/global-settings');

        $response->assertStatus(200)
            ->assertJsonPath('data.portal_settings.show_org_details_on_empty_schedule', true)
            ->assertJsonPath('data.portal_settings.booking_cutoff_time', '17:00');
    }

    /**
     * Test GET /order-slots returns org_details when empty and show_org_details_on_empty_schedule is true
     */
    public function test_get_order_slots_returns_org_details_when_empty_and_configured()
    {
        // Enable the setting for this organization
        Setting::create([
            'key' => 'portal_settings',
            'value' => [
                'show_org_details_on_empty_schedule' => true,
                'disable_next_day_booking' => false,
                'booking_cutoff_time' => '17:00',
            ],
            'org_id' => $this->organization->uuid,
        ]);

        // Mock organization resolution to return our org ID
        app()->instance('current_organization_id', $this->organization->id);

        $response = $this->actingAs($this->orgAdmin, 'api')
            ->getJson('/api/order-slots');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('org_details.name', 'Test Organization')
            ->assertJsonPath('org_details.contact_email', 'contact@testorg.com');
    }

    public function test_create_global_tour_settings()
    {
        app()->instance('current_organization_id', $this->organization->id);

        $payload = [
            'tour_settings' => [
                [
                    'area' => 'Downtown',
                    'type' => 'Travel',
                    'charge' => 50,
                    'discount' => 0,
                    'status' => true,
                ]
            ]
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/global-settings', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'tour_settings' => [
                        '*' => [
                            'uuid',
                            'area',
                            'type',
                            'charge',
                            'discount',
                            'status',
                            'organization_id',
                        ]
                    ]
                ]
            ]);

        $this->assertDatabaseHas('global_tour_settings', [
            'area' => 'Downtown',
            'type' => 'Travel',
            'charge' => 50,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_update_global_tour_settings_bulk()
    {
        app()->instance('current_organization_id', $this->organization->id);

        $setting = GlobalTourSetting::create([
            'uuid' => (string) \Str::uuid(),
            'area' => 'Downtown',
            'type' => 'Travel',
            'charge' => 50,
            'discount' => 0,
            'status' => true,
            'organization_id' => $this->organization->id,
        ]);

        $payload = [
            'tour_settings' => [
                [
                    'uuid' => $setting->uuid,
                    'area' => 'Uptown',
                    'type' => 'Travel',
                    'charge' => 60,
                    'discount' => 5,
                    'status' => true,
                ]
            ]
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson("/api/global-settings/{$setting->uuid}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('global_tour_settings', [
            'uuid' => $setting->uuid,
            'area' => 'Uptown',
            'charge' => 60,
            'discount' => 5,
        ]);
    }

    public function test_update_global_tour_setting_single()
    {
        app()->instance('current_organization_id', $this->organization->id);

        $setting = GlobalTourSetting::create([
            'uuid' => (string) \Str::uuid(),
            'area' => 'Downtown',
            'type' => 'Travel',
            'charge' => 50,
            'discount' => 0,
            'status' => true,
            'organization_id' => $this->organization->id,
        ]);

        $payload = [
            'uuid' => $setting->uuid,
            'area' => 'Midtown',
            'type' => 'Travel',
            'charge' => 55,
            'discount' => 2,
            'status' => false,
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson("/api/global-settings/{$setting->uuid}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('global_tour_settings', [
            'uuid' => $setting->uuid,
            'area' => 'Midtown',
            'charge' => 55,
            'discount' => 2,
            'status' => false,
        ]);
    }
}
