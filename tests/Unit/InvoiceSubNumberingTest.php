<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceSubNumberingTest extends TestCase
{
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = config('database.default');
        config()->set('database.connections.invoice_subnumber_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('invoice_subnumber_test');
        config()->set('database.default', 'invoice_subnumber_test');

        $schema = Schema::connection('invoice_subnumber_test');
        $schema->create('organizations', function ($table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('slug');
            $table->timestamps();
        });

        $schema->create('invoices', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('invoice_number')->unique();
            $table->string('status')->default('issued');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->text('tax_details')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('currency')->default('cad');
            $table->timestamp('issued_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('split_details')->nullable();
            $table->string('agent_type')->default('primary');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        config()->set('database.default', $this->originalDefaultConnection);
        DB::purge('invoice_subnumber_test');
        parent::tearDown();
    }

    public function test_sequence_increments_base_number_ignoring_sub_service_suffixes(): void
    {
        $org = Organization::create(['slug' => 'COMMERX']);
        $year = date('Y');

        // 1. Create Order 1 Main Invoice
        $inv1 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 1,
            'notes' => 'Consolidated Invoice',
        ]);
        $expected1 = sprintf('INV-COMMERX-%s-00001', $year);
        $this->assertEquals($expected1, $inv1->invoice_number);

        // 2. Create Order 1 Service Invoices with sub-numbers -1 and -2
        $inv1_1 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 1,
            'invoice_number' => "{$inv1->invoice_number}-1",
            'notes' => 'Service Invoice: Photo',
        ]);
        $inv1_2 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 1,
            'invoice_number' => "{$inv1->invoice_number}-2",
            'notes' => 'Service Invoice: Video',
        ]);
        $this->assertEquals("{$expected1}-1", $inv1_1->invoice_number);
        $this->assertEquals("{$expected1}-2", $inv1_2->invoice_number);

        // 3. Now create Order 2 Main Invoice - must be 00002, NOT 00003 or 00004!
        $inv2 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 2,
            'notes' => 'Consolidated Invoice',
        ]);
        $expected2 = sprintf('INV-COMMERX-%s-00002', $year);
        $this->assertEquals($expected2, $inv2->invoice_number);

        // 4. Create Order 2 Service Invoice
        $inv2_1 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 2,
            'invoice_number' => "{$inv2->invoice_number}-1",
            'notes' => 'Service Invoice: Drone',
        ]);
        $this->assertEquals("{$expected2}-1", $inv2_1->invoice_number);

        // 5. Create Order 3 Main Invoice - must be 00003
        $inv3 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 3,
            'notes' => 'Consolidated Invoice',
        ]);
        $expected3 = sprintf('INV-COMMERX-%s-00003', $year);
        $this->assertEquals($expected3, $inv3->invoice_number);

        // 6. Add late add-on service to Order 1 - existing was -1 and -2, add-on must be -3
        $existingOrder1ServiceInvoices = Invoice::where('order_id', 1)
            ->where('notes', 'like', 'Service Invoice:%')
            ->pluck('invoice_number');
        $maxExisting = 0;
        foreach ($existingOrder1ServiceInvoices as $num) {
            if (preg_match('/-(\d+)$/', $num, $m)) {
                $maxExisting = max($maxExisting, intval($m[1]));
            }
        }
        $inv1_3 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 1,
            'invoice_number' => "{$inv1->invoice_number}-" . ($maxExisting + 1),
            'notes' => 'Service Invoice: Floor Plan',
        ]);
        $this->assertEquals("{$expected1}-3", $inv1_3->invoice_number);

        // 7. Create Order 4 Main Invoice - must be 00004
        $inv4 = Invoice::create([
            'organization_id' => $org->id,
            'order_id' => 4,
            'notes' => 'Consolidated Invoice',
        ]);
        $expected4 = sprintf('INV-COMMERX-%s-00004', $year);
        $this->assertEquals($expected4, $inv4->invoice_number);
    }
}
