<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\Property;
use App\Models\Order;
use App\Models\OrderSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class VendorPropertyAndNotesTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $organization;
    protected $admin;
    protected $agent;
    protected $vendor;
    protected $property;
    protected $order;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organization
        $this->organization = Organization::create([
            'name' => 'BCF Test Org',
            'slug' => 'bcf-test-org',
        ]);

        // Create Admin
        $this->admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);

        // Create Agent
        $this->agent = Agent::create([
            'first_name' => 'Agent',
            'last_name' => 'Test',
            'email' => 'agent@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);

        // Create Vendor
        $this->vendor = Vendor::create([
            'first_name' => 'Vendor',
            'last_name' => 'Test',
            'email' => 'vendor@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'status' => true,
        ]);

        // Create Property
        $this->property = Property::create([
            'organization_id' => $this->organization->id,
            'agent_id' => $this->agent->id,
            'address' => '123 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'Canada',
        ]);

        // Create Order
        $this->order = Order::create([
            'organization_id' => $this->organization->id,
            'agent_id' => $this->agent->id,
            'property_id' => $this->property->id,
            'amount' => 150.00,
            'order_status' => 'Processing',
            'payment_status' => 'UNPAID',
            'notes' => [
                ['name' => 'Admin', 'note' => 'Public note', 'date' => '2026-07-07 12:00:00', 'internal' => 'false'],
                ['name' => 'Admin', 'note' => 'Internal note', 'date' => '2026-07-07 12:05:00', 'internal' => 'true'],
            ],
        ]);
    }

    /**
     * Test Vendor can update property details when assigned to a slot for the property.
     */
    public function test_vendor_can_update_property_details_when_assigned()
    {
        // Assign vendor to a slot on the order
        OrderSlot::create([
            'order_id' => $this->order->id,
            'service_id' => 1, // dummy service id
            'vendor_id' => $this->vendor->id,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'date' => '2026-07-08',
        ]);

        // Vendor updates property details (only bedrooms/square footage)
        $response = $this->actingAs($this->vendor, 'vendor-api')
            ->putJson("/api/properties/{$this->property->uuid}", [
                'bedrooms' => 4,
                'square_footage' => 2500,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('properties', [
            'id' => $this->property->id,
            'bedrooms' => 4,
            'square_footage' => 2500,
        ]);
    }

    /**
     * Test Vendor is denied from updating property details if not assigned.
     */
    public function test_vendor_cannot_update_property_details_when_unassigned()
    {
        $response = $this->actingAs($this->vendor, 'vendor-api')
            ->putJson("/api/properties/{$this->property->uuid}", [
                'bedrooms' => 4,
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test internal notes visibility rules.
     */
    public function test_internal_notes_visibility()
    {
        // Admin should see both notes
        $responseAdmin = $this->actingAs($this->admin, 'api')
            ->getJson("/api/orders/{$this->order->uuid}");
        $responseAdmin->assertStatus(200);
        $this->assertCount(2, $responseAdmin->json('data.notes'));

        // Vendor should see both notes
        $responseVendor = $this->actingAs($this->vendor, 'vendor-api')
            ->getJson("/api/orders/{$this->order->uuid}");
        $responseVendor->assertStatus(200);
        $this->assertCount(2, $responseVendor->json('data.notes'));

        // Agent should see only the public note
        $responseAgent = $this->actingAs($this->agent, 'agent-api')
            ->getJson("/api/orders/{$this->order->uuid}");
        $responseAgent->assertStatus(200);
        $this->assertCount(1, $responseAgent->json('data.notes'));
        $this->assertEquals('Public note', $responseAgent->json('data.notes.0.note'));
    }

    /**
     * Test Agent updating an order preserves existing internal notes.
     */
    public function test_agent_update_preserves_internal_notes()
    {
        // Agent updates the notes, passing only the note they can see and adding a new note
        $response = $this->actingAs($this->agent, 'agent-api')
            ->putJson("/api/orders/{$this->order->uuid}", [
                'notes' => [
                    ['name' => 'Admin', 'note' => 'Public note', 'date' => '2026-07-07 12:00:00', 'internal' => 'false'],
                    ['name' => 'Agent', 'note' => 'New Agent note', 'date' => '2026-07-07 13:00:00', 'internal' => 'false'],
                ]
            ]);

        $response->assertStatus(200);

        // Fetch order from database directly and verify notes array contains all 3 notes (including the internal one)
        $order = Order::find($this->order->id);
        $this->assertCount(3, $order->notes);

        $notes = collect($order->notes);
        $this->assertTrue($notes->contains('note', 'Internal note'));
        $this->assertTrue($notes->contains('note', 'Public note'));
        $this->assertTrue($notes->contains('note', 'New Agent note'));
    }
}
