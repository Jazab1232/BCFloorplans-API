<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use App\Models\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class VendorInvoiceManualPaymentTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $organization;
    protected $admin;
    protected $vendor;
    protected $invoice;
    protected $orderService;

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

        // Create Vendor Invoice (draft)
        $this->invoice = VendorInvoice::create([
            'organization_id' => $this->organization->id,
            'vendor_id' => $this->vendor->uuid,
            'status' => 'draft',
            'subtotal' => 100.00,
            'tax_amount' => 5.00,
            'travel_amount' => 10.00,
            'total_amount' => 115.00,
            'currency' => 'usd',
            'notes' => 'Original invoice notes',
        ]);

        // Create Order Service associated with the invoice
        $this->orderService = OrderService::create([
            'organization_id' => $this->organization->id,
            'order_id' => 1,
            'service_id' => 1,
            'vendor_id' => $this->vendor->uuid,
            'amount' => 100.00,
            'is_completed' => true,
            'vendor_paid' => false,
            'vendor_invoice_id' => $this->invoice->id,
        ]);

        // Create line item
        VendorInvoiceLine::create([
            'vendor_invoice_id' => $this->invoice->id,
            'order_service_id' => $this->orderService->id,
            'description' => 'Test service line',
            'amount' => 100.00,
            'type' => 'service',
        ]);
    }

    /**
     * Unauthenticated user cannot access manual payment endpoint.
     */
    public function test_unauthenticated_user_cannot_pay_manually()
    {
        $response = $this->postJson("/api/vendor-billing/pay-manual/{$this->invoice->uuid}");
        $response->assertStatus(401);
    }

    /**
     * A vendor cannot access the admin manual payment endpoint.
     */
    public function test_vendor_cannot_pay_manually()
    {
        $response = $this->actingAs($this->vendor, 'vendor-api')
            ->postJson("/api/vendor-billing/pay-manual/{$this->invoice->uuid}");
        $response->assertStatus(403);
    }

    /**
     * Admin can successfully mark an invoice as paid manually.
     */
    public function test_admin_can_pay_manually()
    {
        // Suppress job dispatch queue so we don't try to sync to real QuickBooks during test
        \Illuminate\Support\Facades\Queue::fake();

        $paymentDate = '2026-07-08 10:00:00';
        $paymentNotes = 'Paid outside of stripe using wire transfer #98765';

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/vendor-billing/pay-manual/{$this->invoice->uuid}", [
                'paid_at' => $paymentDate,
                'notes' => $paymentNotes,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Invoice marked as paid manually.',
            ]);

        // Verify invoice was updated
        $this->invoice->refresh();
        $this->assertEquals('paid', $this->invoice->status);
        $this->assertEquals('manual', $this->invoice->stripe_transfer_id);
        $this->assertStringContainsString('Paid outside of stripe using wire transfer #98765', $this->invoice->notes);
        $this->assertStringContainsString('[Manual Payment -', $this->invoice->notes);
        $this->assertNotNull($this->invoice->paid_at);

        // Verify order service was updated
        $this->orderService->refresh();
        $this->assertTrue($this->orderService->vendor_paid);
        $this->assertNotNull($this->orderService->vendor_paid_at);

        // Verify QuickBooks sync job was dispatched
        \Illuminate\Support\Facades\Queue::assertDispatched(\App\Jobs\SyncVendorPayoutToQuickBooks::class);
    }

    /**
     * Cannot mark an already paid invoice as paid.
     */
    public function test_cannot_pay_already_paid_invoice()
    {
        // Mark it as paid first
        $this->invoice->update(['status' => 'paid']);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/vendor-billing/pay-manual/{$this->invoice->uuid}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invoice already paid.',
            ]);
    }
}
