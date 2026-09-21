<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class SignatureApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an organization and a user for testing
        $this->organization = Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org-' . rand(1000, 9999),
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test listing signatures for an organization.
     */
    public function test_can_list_signatures_for_organization()
    {
        Signature::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Signature 1',
            'html_content' => '<p>Regards,</p>',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/organizations/{$this->organization->uuid}/signatures");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Test Signature 1');
    }

    /**
     * Test creating a signature.
     */
    public function test_can_create_signature()
    {
        $data = [
            'name' => 'New Signature',
            'html_content' => '<div>My Signature</div>',
            'media_url' => 'https://example.com/logo.png',
        ];

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/organizations/{$this->organization->uuid}/signatures", $data);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Signature');

        $this->assertDatabaseHas('email_signatures', [
            'name' => 'New Signature',
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test showing a single signature.
     */
    public function test_can_show_signature()
    {
        $signature = Signature::create([
            'organization_id' => $this->organization->id,
            'name' => 'Single Sig',
            'html_content' => 'Test',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/signatures/{$signature->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Single Sig');
    }

    /**
     * Test updating a signature.
     */
    public function test_can_update_signature()
    {
        $signature = Signature::create([
            'organization_id' => $this->organization->id,
            'name' => 'Old Name',
            'html_content' => 'Old Content',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->putJson("/api/signatures/{$signature->uuid}", [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('email_signatures', [
            'uuid' => $signature->uuid,
            'name' => 'Updated Name',
        ]);
    }

    /**
     * Test deleting a signature.
     */
    public function test_can_delete_signature()
    {
        $signature = Signature::create([
            'organization_id' => $this->organization->id,
            'name' => 'To Be Deleted',
            'html_content' => 'Bye',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->deleteJson("/api/signatures/{$signature->uuid}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('email_signatures', [
            'uuid' => $signature->uuid,
        ]);
    }
}
