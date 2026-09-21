<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Order;
use App\Models\Tour;
use App\Models\TourFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class TourSubtypeTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $admin;
    protected $order;
    protected $tour;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org-' . rand(100, 999),
            'contact_email' => 'org@test.com',
            'contact_phone' => '1234567890',
            'address_line_1' => '123 Main St',
            'city' => 'Metropolis',
            'province' => 'BC',
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin@test.com',
            'organization_id' => $org->id,
        ]);

        $this->order = Order::create([
            'uuid' => (string) \Str::uuid(),
            'organization_id' => $org->id,
            'property_address' => '123 Test Street',
            'property_city' => 'Vancouver',
            'property_province' => 'BC',
            'status' => 'COMPLETED',
        ]);

        $this->tour = Tour::create([
            'uuid' => (string) \Str::uuid(),
            'order_id' => $this->order->id,
            'is_publish' => true,
        ]);
    }

    public function test_can_save_tour_file_with_subtype()
    {
        $file = TourFile::create([
            'uuid' => (string) \Str::uuid(),
            'tour_id' => $this->tour->id,
            'type' => 'photo',
            'subtype' => 'panorama_360',
            'name' => 'Living Room 360',
            'file_path' => 'tours/test.jpg',
        ]);

        $this->assertDatabaseHas('tour_files', [
            'uuid' => $file->uuid,
            'subtype' => 'panorama_360',
        ]);
    }
}