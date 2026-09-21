<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAddress;
use App\Models\VendorSetting;
use App\Models\OrderService;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class VendorTaxBillingTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $organization;
    protected $admin;
    protected $vendor;
    protected $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Tax Test Org',
            'slug' => 'tax-test-org',
        ]);

        $this->admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'TaxTest',
            'email' => 'admin@taxtest.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
        ]);

        $this->vendor = Vendor::create([
            'first_name' => 'Vendor',
            'last_name' => 'TaxTest',
            'email' => 'vendor@taxtest.com',
            'password' => bcrypt('password'),
            'organization_id' => $this->organization->id,
            'status' => true,
        ]);

        // Create default settings structure
        VendorSetting::create([
            'vendor_id' => $this->vendor->id,
            'payment_per_km' => 0.45,
            'is_kilometers' => true,
        ]);

        // Create default address
        VendorAddress::create([
            'vendor_id' => $this->vendor->id,
            'type' => 'billing',
            'address_line_1' => '123 Main St',
            'city' => 'Toronto',
            'province' => 'ON',
            'country' => 'Canada',
        ]);

        $this->orderService = OrderService::create([
            'organization_id' => $this->organization->id,
            'order_id' => 1,
            'service_id' => 1,
            'vendor_id' => $this->vendor->uuid,
            'amount' => 100.00,
            'is_completed' => true,
        ]);
    }

    /**
     * Test vendor settings can store detailed tax fields via controller update.
     */
    public function test_vendor_settings_can_store_detailed_tax_fields()
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson("/api/vendors/{$this->vendor->uuid}", [
                'first_name' => 'UpdatedVendor',
                'settings' => [
                    'tax_enabled' => true,
                    'tax_rate' => 13.00,
                    'tax_number' => '123456789RT0001',
                    'tax_country' => 'CA',
                    'tax_type' => 'GST_HST',
                    'tax_number_gst_hst' => '123456789RT0001',
                    'tax_number_pst' => 'PST-999',
                    'tax_number_qst' => null,
                    'tax_number_us' => null,
                    'tax_exempt' => false,
                ]
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('vendor_settings', [
            'vendor_id' => $this->vendor->id,
            'tax_enabled' => 1,
            'tax_rate' => 13.00,
            'tax_country' => 'CA',
            'tax_type' => 'GST_HST',
            'tax_number_gst_hst' => '123456789RT0001',
            'tax_number_pst' => 'PST-999',
            'tax_exempt' => 0,
        ]);
    }

    /**
     * Test auto calculation of Canadian tax rate based on province.
     */
    public function test_generate_invoice_auto_calculates_canada_tax()
    {
        // 1. Setup vendor tax settings
        $this->vendor->settings->update([
            'tax_enabled' => true,
            'tax_country' => 'CA',
            'tax_type' => 'GST_HST',
            'tax_number_gst_hst' => 'GST-HST-111',
            'tax_number_pst' => 'PST-222',
        ]);

        // 2. Generate invoice for 100.00
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/vendor-billing/generate', [
                'vendor_uuid' => $this->vendor->uuid,
                'order_service_uuids' => [
                    $this->orderService->uuid,
                ],
            ]);

        $response->assertStatus(200);

        // ON should resolve to 13.00% HST
        $data = $response->json('data');
        $this->assertEquals(13.00, (float)$data['tax_rate']);
        $this->assertEquals('HST', $data['tax_type']);
        $this->assertStringContainsString('GST/HST: GST-HST-111', $data['tax_number']);
        $this->assertEquals(13.00, (float)$data['tax_amount']); // 100 * 13% = 13.00
        $this->assertEquals(113.00, (float)$data['total_amount']); // 100 + 13 = 113.00
    }

    /**
     * Test auto calculation for Quebec vendor.
     */
    public function test_generate_invoice_auto_calculates_quebec_tax()
    {
        // Update Address to Quebec
        $this->vendor->addresses()->where('type', 'billing')->first()->update([
            'province' => 'QC',
            'city' => 'Montreal',
        ]);

        $this->vendor->settings->update([
            'tax_enabled' => true,
            'tax_country' => 'CA',
            'tax_type' => 'GST_QST',
            'tax_number_gst_hst' => 'GST-111',
            'tax_number_qst' => 'QST-333',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/vendor-billing/generate', [
                'vendor_uuid' => $this->vendor->uuid,
                'order_service_uuids' => [
                    $this->orderService->uuid,
                ],
            ]);

        $response->assertStatus(200);

        // QC should resolve to 14.975% GST + QST
        $data = $response->json('data');
        $this->assertEquals(14.975, (float)$data['tax_rate']);
        $this->assertEquals('GST + QST', $data['tax_type']);
        $this->assertStringContainsString('GST/HST: GST-111', $data['tax_number']);
        $this->assertStringContainsString('QST: QST-333', $data['tax_number']);
        $this->assertEquals(14.98, round((float)$data['tax_amount'], 2)); // 100 * 14.975% = 14.975 (rounds to 14.98)
    }

    /**
     * Test generation respects exempt status.
     */
    public function test_generate_invoice_respects_exempt_status()
    {
        $this->vendor->settings->update([
            'tax_enabled' => true,
            'tax_exempt' => true,
            'tax_rate' => 13.00,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/vendor-billing/generate', [
                'vendor_uuid' => $this->vendor->uuid,
                'order_service_uuids' => [
                    $this->orderService->uuid,
                ],
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(0.00, (float)$data['tax_rate']);
        $this->assertEquals(0.00, (float)$data['tax_amount']);
        $this->assertEquals(100.00, (float)$data['total_amount']);
    }

    /**
     * Test overrides during generation.
     */
    public function test_generate_invoice_accepts_overrides()
    {
        $this->vendor->settings->update([
            'tax_enabled' => true,
            'tax_country' => 'CA',
            'tax_type' => 'GST_HST',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/vendor-billing/generate', [
                'vendor_uuid' => $this->vendor->uuid,
                'order_service_uuids' => [
                    $this->orderService->uuid,
                ],
                'tax_rate' => 8.50,
                'tax_type' => 'Custom rate override',
                'tax_number' => 'OVERRIDE-123',
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(8.50, (float)$data['tax_rate']);
        $this->assertEquals('Custom rate override', $data['tax_type']);
        $this->assertEquals('OVERRIDE-123', $data['tax_number']);
        $this->assertEquals(8.50, (float)$data['tax_amount']);
        $this->assertEquals(108.50, (float)$data['total_amount']);
    }

    /**
     * Test invoice update endpoint updates tax fields and recalculates.
     */
    public function test_update_invoice_recalculates_totals()
    {
        // 1. Create a draft invoice
        $invoice = VendorInvoice::create([
            'organization_id' => $this->organization->id,
            'vendor_id' => $this->vendor->uuid,
            'status' => 'draft',
            'subtotal' => 100.00,
            'tax_rate' => 5.00,
            'tax_amount' => 5.00,
            'total_amount' => 105.00,
        ]);

        // Create associated line item
        VendorInvoiceLine::create([
            'vendor_invoice_id' => $invoice->id,
            'order_service_id' => $this->orderService->id,
            'description' => 'Test service line',
            'amount' => 100.00,
            'type' => 'service',
        ]);

        // 2. Perform patch update via API
        $response = $this->actingAs($this->admin, 'api')
            ->patchJson("/api/vendor-billing/invoices/{$invoice->uuid}", [
                'notes' => 'Updated invoice notes',
                'tax_rate' => 10.00,
                'tax_type' => 'State Tax',
                'tax_number' => 'STATE-111',
            ]);

        $response->assertStatus(200);

        // Verify values recalculated with 10% tax rate
        $this->assertDatabaseHas('vendor_invoices', [
            'uuid' => $invoice->uuid,
            'notes' => 'Updated invoice notes',
            'tax_rate' => 10.00,
            'tax_type' => 'State Tax',
            'tax_number' => 'STATE-111',
            'tax_amount' => 10.00,
            'total_amount' => 110.00,
        ]);
    }
}
