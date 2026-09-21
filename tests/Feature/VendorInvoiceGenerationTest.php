<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\OrderService;
use App\Models\OrderSlot;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class VendorInvoiceGenerationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $organization;
    protected $admin;
    protected $vendor;
    protected $orderService1;
    protected $orderService2;

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

        // Create Vendor
        $this->vendor = Vendor::create([
            'first_name' => 'Vendor',
            'last_name' => 'Test',
            'email' => 'vendor@test.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'status' => true,
        ]);

        // Create Order Services
        $this->orderService1 = OrderService::create([
            'organization_id' => $this->organization->id,
            'order_id' => 1,
            'service_id' => 1,
            'vendor_id' => $this->vendor->uuid,
            'amount' => 100.00,
            'is_completed' => true,
        ]);

        $this->orderService2 = OrderService::create([
            'organization_id' => $this->organization->id,
            'order_id' => 1,
            'service_id' => 2,
            'vendor_id' => $this->vendor->uuid,
            'amount' => 200.00,
            'is_completed' => true,
        ]);
    }

    /**
     * Test admin can generate vendor invoice.
     */
    public function test_admin_can_generate_vendor_invoice()
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/vendor-billing/generate', [
                'vendor_uuid' => $this->vendor->uuid,
                'order_service_uuids' => [
                    $this->orderService1->uuid,
                    $this->orderService2->uuid,
                ],
                'notes' => 'Test generation notes',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('vendor_invoices', [
            'vendor_id' => $this->vendor->uuid,
            'notes' => 'Test generation notes',
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Test admin can generate vendor invoice with custom lines.
     */
    public function test_admin_can_generate_with_custom_lines()
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/vendor-billing/generate', [
                'vendor_uuid' => $this->vendor->uuid,
                'notes' => 'Custom lines invoice',
                'tax_rate' => 13.0,
                'lines' => [
                    [
                        'description' => 'Custom service 1',
                        'quantity' => 1,
                        'unit_price' => 150.00,
                        'amount' => 150.00,
                        'type' => 'service',
                        'order_service_id' => $this->orderService1->uuid,
                    ],
                    [
                        'description' => 'Travel compensation',
                        'quantity' => 1,
                        'unit_price' => 45.00,
                        'amount' => 45.00,
                        'type' => 'travel',
                    ],
                    [
                        'description' => 'Custom adjustment',
                        'quantity' => 1,
                        'unit_price' => -10.00,
                        'amount' => -10.00,
                        'type' => 'adjustment',
                    ]
                ]
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $invoiceUuid = $data['uuid'];

        $this->assertDatabaseHas('vendor_invoices', [
            'uuid' => $invoiceUuid,
            'organization_id' => $this->organization->id,
            'subtotal' => 150.00,
            'travel_amount' => 45.00,
            'tax_amount' => 25.35, // (150 + 45) * 13% = 25.35
            'total_amount' => 210.35, // 150 + 45 - 10 + 25.35 = 210.35
        ]);

        $this->assertDatabaseHas('vendor_invoice_lines', [
            'vendor_invoice_id' => $data['id'],
            'description' => 'Custom service 1',
            'amount' => 150.00,
            'type' => 'service',
        ]);

        $this->assertDatabaseHas('vendor_invoice_lines', [
            'vendor_invoice_id' => $data['id'],
            'description' => 'Travel compensation',
            'amount' => 45.00,
            'type' => 'travel',
        ]);

        // Verify order service was linked
        $this->orderService1->refresh();
        $this->assertEquals($data['id'], $this->orderService1->vendor_invoice_id);
    }

    /**
     * Test admin can generate vendor invoice with custom items array (frontend create format).
     */
    public function test_admin_can_generate_with_custom_items()
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/vendor-billing/generate', [
                'vendor_uuid' => $this->vendor->uuid,
                'notes' => 'Custom items format',
                'tax_rate' => 5.0,
                'items' => [
                    [
                        'description' => 'Item service 2',
                        'quantity' => 2,
                        'unit_price' => 80.00,
                        'amount' => 160.00,
                        'type' => 'service',
                        'order_service_uuid' => $this->orderService2->uuid,
                    ]
                ]
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $invoiceUuid = $data['uuid'];

        $this->assertDatabaseHas('vendor_invoices', [
            'uuid' => $invoiceUuid,
            'organization_id' => $this->organization->id,
            'subtotal' => 160.00,
            'tax_amount' => 8.00, // 160 * 5% = 8.00
            'total_amount' => 168.00,
        ]);

        $this->orderService2->refresh();
        $this->assertEquals($data['id'], $this->orderService2->vendor_invoice_id);
    }
}
