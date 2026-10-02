<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\Agent;
use App\Models\Order;
use App\Models\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SplitInvoicePaymentStatusTest extends TestCase
{
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = config('database.default');
        config()->set('database.connections.split_invoice_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('split_invoice_test');
        config()->set('database.default', 'split_invoice_test');

        $schema = Schema::connection('split_invoice_test');
        $schema->create('orders', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('payment_status')->default('UNPAID');
            $table->boolean('split_invoice')->default(false);
            $table->json('co_agents')->nullable();
            $table->timestamps();
        });
        $schema->create('order_services', function ($table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->uuid('uuid');
            $table->string('payment_status')->default('UNPAID');
            $table->timestamps();
        });
        $schema->create('invoices', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('agent_id');
            $table->string('invoice_number');
            $table->string('status');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->json('split_details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        $schema->create('invoice_items', function ($table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('order_service_id')->nullable();
            $table->boolean('is_extra')->default(false);
            $table->string('description')->default('Service');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('split_invoice_test');
        config()->set('database.default', $this->originalDefaultConnection);

        parent::tearDown();
    }

    public function test_one_agent_payment_does_not_settle_another_agents_service_share(): void
    {
        $now = now();
        DB::table('orders')->insert([
            'id' => 1,
            'uuid' => 'order-uuid',
            'agent_id' => 10,
            'amount' => 200,
            'paid_amount' => 140,
            'payment_status' => 'PARTIAL',
            'split_invoice' => true,
            'co_agents' => json_encode([['agent_id' => 20, 'percentage' => 30]]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('order_services')->insert([
            'id' => 1,
            'order_id' => 1,
            'uuid' => 'service-uuid',
            'payment_status' => 'UNPAID',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $splitDetails = json_encode(['splits' => [
            ['agent_id' => 10, 'percentage' => 70],
            ['agent_id' => 20, 'percentage' => 30],
        ]]);
        DB::table('invoices')->insert([
            [
                'id' => 1,
                'uuid' => 'primary-invoice-uuid',
                'order_id' => 1,
                'agent_id' => 10,
                'invoice_number' => 'INV-PRIMARY',
                'status' => 'paid',
                'subtotal' => 140,
                'total' => 140,
                'paid_amount' => 140,
                'split_details' => $splitDetails,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'uuid' => 'co-agent-invoice-uuid',
                'order_id' => 1,
                'agent_id' => 20,
                'invoice_number' => 'INV-CO-AGENT',
                'status' => 'issued',
                'subtotal' => 60,
                'total' => 60,
                'paid_amount' => 0,
                'split_details' => $splitDetails,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        DB::table('invoice_items')->insert([
            ['invoice_id' => 1, 'order_service_id' => 1, 'amount' => 140, 'unit_price' => 140, 'created_at' => $now, 'updated_at' => $now],
            ['invoice_id' => 2, 'order_service_id' => 1, 'amount' => 60, 'unit_price' => 60, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $order = Order::findOrFail(1);
        $service = OrderService::findOrFail(1);
        $coAgent = new Agent();
        $coAgent->id = 20;
        $coAgent->uuid = 'co-agent-uuid';
        $coAgent->email = 'coagent@example.com';

        $this->assertTrue(Order::withoutGlobalScopes()->forAgent($coAgent)->whereKey($order->id)->exists());

        Invoice::syncOrderServicePaymentStatus($order, $service);
        Invoice::syncOrderStatus($order);

        $this->assertSame('UNPAID', $service->fresh()->payment_status);
        $this->assertSame('paid', Invoice::findOrFail(1)->status);
        $this->assertSame('issued', Invoice::findOrFail(2)->status);
        $this->assertSame('0.00', Invoice::findOrFail(2)->paid_amount);

        DB::table('invoices')->where('id', 2)->update([
            'status' => 'paid',
            'paid_amount' => 60,
        ]);

        Invoice::syncOrderServicePaymentStatus($order, $service);

        $this->assertSame('PAID', $service->fresh()->payment_status);
    }
}
