<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiscountOptionalServicesTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $superAdmin;
    protected ServiceCategory $category;
    protected Service $service;

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

        $this->category = ServiceCategory::create([
            'name' => 'Photography',
            'type' => ['area'],
            'duration' => true,
            'add_ons' => true,
        ]);

        $this->service = Service::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Photo Service',
            'category_id' => $this->category->id,
            'organization_id' => null,
            'status' => true,
        ]);
    }

    public function test_can_create_code_discount_with_empty_services_array(): void
    {
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/discounts', [
                'type' => 'code',
                'code_key' => 'ALLSERVICES20',
                'percentage' => 20,
                'description' => '20% off all services',
                'services' => [],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonCount(0, 'data.services');

        $this->assertDatabaseHas('discounts', [
            'type' => 'code',
            'code_key' => 'ALLSERVICES20',
        ]);
        $this->assertDatabaseCount('discount_services', 0);
    }

    public function test_can_create_code_discount_omitting_services(): void
    {
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/discounts', [
                'type' => 'code',
                'code_key' => 'NOSVC15',
                'percentage' => 15,
                'description' => '15% off all services',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonCount(0, 'data.services');

        $this->assertDatabaseCount('discount_services', 0);
    }

    public function test_can_create_quantity_discount_with_empty_services_array(): void
    {
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/discounts', [
                'type' => 'quantity',
                'name' => 'Bundle 3+ Any Services',
                'quantity' => 3,
                'percentage' => 10,
                'expiry_date' => now()->addMonth()->toDateString(),
                'description' => '10% off when ordering 3+ services',
                'services' => [],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonCount(0, 'data.services');

        $this->assertDatabaseHas('discounts', [
            'type' => 'quantity',
            'name' => 'Bundle 3+ Any Services',
            'quantity' => 3,
        ]);
        $this->assertDatabaseCount('discount_services', 0);
    }

    public function test_can_update_discount_to_empty_services_array_and_detach_existing_services(): void
    {
        $discount = Discount::create([
            'uuid' => (string) Str::uuid(),
            'type' => 'code',
            'code_key' => 'DETACHME',
            'percentage' => 25,
            'description' => 'Initially scoped',
        ]);
        $discount->services()->sync([$this->service->id]);

        $this->assertDatabaseCount('discount_services', 1);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson("/api/discounts/{$discount->uuid}", [
                'type' => 'code',
                'code_key' => 'DETACHME',
                'percentage' => 25,
                'description' => 'Now applies to all services',
                'services' => [],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonCount(0, 'data.services');

        $this->assertDatabaseCount('discount_services', 0);
    }

    public function test_creating_discount_with_nonexistent_service_id_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/discounts', [
                'type' => 'code',
                'code_key' => 'INVALIDSVC',
                'percentage' => 10,
                'services' => [999999],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['services.0']);
    }
}
