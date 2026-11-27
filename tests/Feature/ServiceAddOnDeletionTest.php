<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationService;
use App\Models\Service;
use App\Models\ServiceAddOn;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceAddOnDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $superAdmin;
    protected User $orgAdmin;
    protected ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org-' . Str::lower(Str::random(6)),
            'contact_email' => 'contact@testorg.com',
            'contact_phone' => '123-456-7890',
            'address_line_1' => '123 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
        ]);

        $this->superAdmin = User::factory()->create([
            'email' => 'todd@tojuco.com',
            'organization_id' => null,
        ]);

        $this->orgAdmin = User::factory()->create([
            'email' => 'admin@testorg.com',
            'organization_id' => $this->organization->id,
        ]);

        $this->category = ServiceCategory::create([
            'name' => 'Photography',
            'type' => ['area'],
            'duration' => true,
            'add_ons' => true,
        ]);
    }

    private function createServiceWithAddOns(?int $organizationId, array $addOns): Service
    {
        $service = Service::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Service ' . Str::random(4),
            'category_id' => $this->category->id,
            'organization_id' => $organizationId,
            'status' => true,
        ]);

        foreach ($addOns as $addOn) {
            ServiceAddOn::create([
                'uuid' => $addOn['uuid'] ?? (string) Str::uuid(),
                'service_id' => $service->id,
                'title' => $addOn['title'],
                'amount' => $addOn['amount'],
            ]);
        }

        return $service->fresh(['serviceAddOns']);
    }

    public function test_super_admin_can_delete_one_addon_from_global_service(): void
    {
        $service = $this->createServiceWithAddOns(null, [
            ['title' => 'Rush Delivery', 'amount' => 50],
            ['title' => 'Twilight Edit', 'amount' => 75],
        ]);

        $keepAddOn = $service->serviceAddOns->firstWhere('title', 'Rush Delivery');
        $deleteAddOn = $service->serviceAddOns->firstWhere('title', 'Twilight Edit');

        $response = $this->actingAs($this->superAdmin, 'api')
            ->post("/api/services/{$service->uuid}", [
                '_method' => 'PUT',
                'sync_add_ons' => '1',
                'add_ons' => [
                    [
                        'uuid' => $keepAddOn->uuid,
                        'title' => 'Rush Delivery',
                        'amount' => '50',
                    ],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.service_add_ons')
            ->assertJsonPath('data.service_add_ons.0.uuid', $keepAddOn->uuid)
            ->assertJsonPath('data.service_add_ons.0.title', 'Rush Delivery');

        $this->assertDatabaseHas('service_add_ons', [
            'id' => $keepAddOn->id,
            'uuid' => $keepAddOn->uuid,
        ]);
        $this->assertDatabaseMissing('service_add_ons', [
            'id' => $deleteAddOn->id,
        ]);
    }

    public function test_super_admin_can_delete_all_addons_via_sync_add_ons_flag(): void
    {
        $service = $this->createServiceWithAddOns(null, [
            ['title' => 'Rush Delivery', 'amount' => 50],
            ['title' => 'Twilight Edit', 'amount' => 75],
        ]);

        // Simulates FormData submission when all add-ons are deleted (no add_ons[*] fields, sync_add_ons=1)
        $response = $this->actingAs($this->superAdmin, 'api')
            ->post("/api/services/{$service->uuid}", [
                '_method' => 'PUT',
                'sync_add_ons' => '1',
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.service_add_ons');

        $this->assertDatabaseCount('service_add_ons', 0);

        $showResponse = $this->actingAs($this->superAdmin, 'api')
            ->getJson("/api/services/{$service->uuid}");

        $showResponse->assertStatus(200)
            ->assertJsonCount(0, 'data.service_add_ons');
    }

    public function test_super_admin_can_delete_all_addons_via_empty_json_array(): void
    {
        $service = $this->createServiceWithAddOns(null, [
            ['title' => 'Rush Delivery', 'amount' => 50],
        ]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson("/api/services/{$service->uuid}", [
                'add_ons' => [],
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.service_add_ons');

        $this->assertDatabaseCount('service_add_ons', 0);
    }

    public function test_org_admin_can_delete_all_addons_from_global_service_without_affecting_master(): void
    {
        $service = $this->createServiceWithAddOns(null, [
            ['title' => 'Master AddOn 1', 'amount' => 40],
            ['title' => 'Master AddOn 2', 'amount' => 80],
        ]);

        $response = $this->actingAs($this->orgAdmin, 'api')
            ->post("/api/services/{$service->uuid}", [
                '_method' => 'PUT',
                'sync_add_ons' => '1',
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.service_add_ons');

        $override = OrganizationService::where('organization_id', $this->organization->id)
            ->where('service_id', $service->id)
            ->first();

        $this->assertNotNull($override);
        $this->assertSame([], $override->add_ons_override);

        // Master add-ons remain intact for other organizations / super admin
        $this->assertDatabaseCount('service_add_ons', 2);

        $showOrgResponse = $this->actingAs($this->orgAdmin, 'api')
            ->getJson("/api/services/{$service->uuid}");
        $showOrgResponse->assertStatus(200)
            ->assertJsonCount(0, 'data.service_add_ons');

        $indexOrgResponse = $this->actingAs($this->orgAdmin, 'api')
            ->getJson("/api/services?slug={$this->organization->slug}");
        $indexOrgResponse->assertStatus(200)
            ->assertJsonCount(0, 'data.0.service_add_ons');

        $showSuperResponse = $this->actingAs($this->superAdmin, 'api')
            ->getJson("/api/services/{$service->uuid}");
        $showSuperResponse->assertStatus(200)
            ->assertJsonCount(2, 'data.service_add_ons');
    }

    public function test_org_admin_can_delete_among_org_override_addons_without_422_error(): void
    {
        $service = $this->createServiceWithAddOns(null, []);

        // First create two org-specific add-ons (stored in add_ons_override JSON, not service_add_ons table)
        $createResponse = $this->actingAs($this->orgAdmin, 'api')
            ->post("/api/services/{$service->uuid}", [
                '_method' => 'PUT',
                'sync_add_ons' => '1',
                'add_ons' => [
                    ['title' => 'Org Custom A', 'amount' => '25'],
                    ['title' => 'Org Custom B', 'amount' => '35'],
                ],
            ]);

        $createResponse->assertStatus(200)
            ->assertJsonCount(2, 'data.service_add_ons');

        $firstUuid = $createResponse->json('data.service_add_ons.0.uuid');
        $this->assertNotEmpty($firstUuid);

        // Now delete Org Custom B while sending Org Custom A's org-only UUID
        $deleteResponse = $this->actingAs($this->orgAdmin, 'api')
            ->post("/api/services/{$service->uuid}", [
                '_method' => 'PUT',
                'sync_add_ons' => '1',
                'add_ons' => [
                    [
                        'uuid' => $firstUuid,
                        'title' => 'Org Custom A',
                        'amount' => '25',
                    ],
                ],
            ]);

        $deleteResponse->assertStatus(200)
            ->assertJsonCount(1, 'data.service_add_ons')
            ->assertJsonPath('data.service_add_ons.0.uuid', $firstUuid)
            ->assertJsonPath('data.service_add_ons.0.title', 'Org Custom A');
    }

    public function test_org_admin_can_delete_all_addons_from_org_owned_service(): void
    {
        $service = $this->createServiceWithAddOns($this->organization->id, [
            ['title' => 'Org Owned AddOn', 'amount' => 60],
        ]);

        $response = $this->actingAs($this->orgAdmin, 'api')
            ->post("/api/services/{$service->uuid}", [
                '_method' => 'PUT',
                'sync_add_ons' => '1',
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data.service_add_ons');

        $override = OrganizationService::where('organization_id', $this->organization->id)
            ->where('service_id', $service->id)
            ->first();

        $this->assertNotNull($override);
        $this->assertSame([], $override->add_ons_override);
        $this->assertDatabaseCount('service_add_ons', 0);
    }

    public function test_partial_update_without_addons_or_sync_flag_preserves_existing_addons(): void
    {
        $service = $this->createServiceWithAddOns(null, [
            ['title' => 'Persistent AddOn', 'amount' => 45],
        ]);

        $globalUpdate = $this->actingAs($this->superAdmin, 'api')
            ->putJson("/api/services/{$service->uuid}", [
                'name' => 'Renamed Service',
            ]);

        $globalUpdate->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed Service')
            ->assertJsonCount(1, 'data.service_add_ons');

        $this->assertDatabaseCount('service_add_ons', 1);

        $orgUpdate = $this->actingAs($this->orgAdmin, 'api')
            ->putJson("/api/services/{$service->uuid}", [
                'gst_enabled' => false,
            ]);

        $orgUpdate->assertStatus(200)
            ->assertJsonCount(1, 'data.service_add_ons');
    }
}
