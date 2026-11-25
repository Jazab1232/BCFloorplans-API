<?php

namespace Tests\Unit;

use App\Models\Agent;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Service;
use App\Models\OrderService;
use App\Services\QuickBooksService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class QuickBooksTaxPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function buildSampleInvoice(Organization $org): Invoice
    {
        $property = new Property(['address' => '4288 Fraser Street']);
        $agent = new Agent(['first_name' => 'Charlotte', 'last_name' => 'Harris']);
        $order = new Order(['id' => 470, 'organization_id' => $org->id]);
        $order->setRelation('property', $property);
        $order->setRelation('organization', $org);

        $svc1 = new Service(['name' => '2D Floor Plans', 'price' => 250.00]);
        $svc1->id = 1;
        $svc1->quickbooks_item_id = '55';

        $svc2 = new Service(['name' => 'HDR Still Photos', 'price' => 263.00]);
        $svc2->id = 2;
        $svc2->quickbooks_item_id = '57';

        $os1 = new OrderService();
        $os1->setRelation('service', $svc1);
        $os2 = new OrderService();
        $os2->setRelation('service', $svc2);

        $item1 = new InvoiceItem([
            'description' => '2D Floor Plans',
            'quantity' => 1,
            'unit_price' => 250.00,
            'amount' => 250.00,
        ]);
        $item1->setRelation('orderService', $os1);

        $item2 = new InvoiceItem([
            'description' => 'HDR Still Photos',
            'quantity' => 1,
            'unit_price' => 263.00,
            'amount' => 263.00,
        ]);
        $item2->setRelation('orderService', $os2);

        $discountItem = new InvoiceItem([
            'description' => 'Agent Discount: TESTING (15%)',
            'quantity' => 1,
            'unit_price' => -76.95,
            'amount' => -76.95,
        ]);

        $invoice = new Invoice([
            'id' => 975,
            'organization_id' => $org->id,
            'order_id' => 470,
            'invoice_number' => 'INV-COMMERX-2026-00009',
            'subtotal' => 436.05,
            'tax_amount' => 63.62,
            'total' => 499.67,
            'tax_details' => [
                'GST' => ['rate' => 11, 'amount' => 47.97],
                'PST' => ['rate' => 7, 'amount' => 15.65],
            ],
        ]);
        $invoice->setRelation('organization', $org);
        $invoice->setRelation('order', $order);
        $invoice->setRelation('agent', $agent);
        $invoice->setRelation('items', new Collection([$item1, $item2, $discountItem]));

        return $invoice;
    }

    private function makeStubDataService(string $country, bool $usingSalesTax, array $taxCodes = [], array $taxRates = []): object
    {
        return new class($country, $usingSalesTax, $taxCodes, $taxRates) {
            public function __construct(
                private string $country,
                private bool $usingSalesTax,
                private array $taxCodes,
                private array $taxRates
            ) {}

            public function getCompanyInfo()
            {
                return (object)['Country' => $this->country, 'CompanyName' => 'Test Company'];
            }

            public function getCompanyPreferences()
            {
                return (object)[
                    'TaxPrefs' => (object)['UsingSalesTax' => $this->usingSalesTax],
                ];
            }

            public function FindById($entity, $id)
            {
                return (object)['Id' => (string)$id, 'Name' => 'Cached Item'];
            }

            public function Query($sql)
            {
                if (stripos($sql, 'FROM TaxCode') !== false) {
                    return $this->taxCodes;
                }
                if (stripos($sql, 'FROM TaxRate') !== false) {
                    return $this->taxRates;
                }
                if (stripos($sql, "Name = 'Sales Tax'") !== false) {
                    return [(object)['Id' => '99', 'Name' => 'Sales Tax']];
                }
                return [];
            }
        };
    }

    public function test_canadian_company_uses_native_txn_tax_detail_and_no_tax_line_item(): void
    {
        $org = new Organization(['qb_realm_id' => 'realm-ca-1']);
        $org->id = 10;
        $invoice = $this->buildSampleInvoice($org);

        $ds = $this->makeStubDataService(
            'CA',
            true,
            [(object)['Id' => '4', 'Name' => 'GST/PST (18%)', 'Taxable' => 'true']],
            [
                (object)['Id' => '6', 'Name' => 'GST', 'RateValue' => 11],
                (object)['Id' => '7', 'Name' => 'PST', 'RateValue' => 7],
            ]
        );

        // Pre-mark cached items verified so no FindById network assumptions exist
        Cache::put('qb_item_verified_realm-ca-1_1_55', true, 3600);
        Cache::put('qb_item_verified_realm-ca-1_2_57', true, 3600);

        $service = new QuickBooksService();
        $ref = new \ReflectionProperty(QuickBooksService::class, 'organization');
        $ref->setAccessible(true);
        $ref->setValue($service, $org);

        $method = new ReflectionMethod(QuickBooksService::class, 'prepareInvoicePayload');
        $method->setAccessible(true);
        $payload = $method->invoke($service, $ds, $invoice, $org, '66');

        // Native Canadian tax structure assertions
        $this->assertSame('TaxExcluded', $payload['GlobalTaxCalculation']);
        $this->assertSame('true', $payload['ApplyTaxAfterDiscount']);
        $this->assertSame(63.62, $payload['TxnTaxDetail']['TotalTax']);
        $this->assertSame('4', $payload['TxnTaxDetail']['TxnTaxCodeRef']['value']);
        $this->assertCount(2, $payload['TxnTaxDetail']['TaxLine']);
        $this->assertSame(47.97, $payload['TxnTaxDetail']['TaxLine'][0]['Amount']);
        $this->assertSame(15.65, $payload['TxnTaxDetail']['TaxLine'][1]['Amount']);

        // Ensure lines contain 2 service lines + 1 native discount (NO 'Sales Tax' line item!)
        $this->assertCount(3, $payload['Line']);
        $this->assertSame('SalesItemLineDetail', $payload['Line'][0]->DetailType);
        $this->assertSame('4', $payload['Line'][0]->SalesItemLineDetail->TaxCodeRef->value);
        $this->assertSame('SalesItemLineDetail', $payload['Line'][1]->DetailType);
        $this->assertSame('DiscountLineDetail', $payload['Line'][2]->DetailType);
        $this->assertSame(76.95, $payload['Line'][2]->Amount);
    }

    public function test_us_company_with_sales_tax_enabled_uses_native_txn_tax_detail_and_custom_code(): void
    {
        $org = new Organization(['qb_realm_id' => 'realm-us-on']);
        $org->id = 11;
        $invoice = $this->buildSampleInvoice($org);

        $ds = $this->makeStubDataService(
            'US',
            true,
            [
                (object)['Id' => 'TAX', 'Name' => 'TAX', 'Taxable' => 'true'],
                (object)['Id' => 'NON', 'Name' => 'NON', 'Taxable' => 'false'],
                (object)['Id' => 'CustomSalesTax', 'Name' => 'CustomSalesTax', 'Taxable' => 'true'],
                (object)['Id' => '5', 'Name' => 'GST_PST_18', 'Taxable' => 'true'],
            ]
        );

        Cache::put('qb_item_verified_realm-us-on_1_55', true, 3600);
        Cache::put('qb_item_verified_realm-us-on_2_57', true, 3600);

        $service = new QuickBooksService();
        $ref = new \ReflectionProperty(QuickBooksService::class, 'organization');
        $ref->setAccessible(true);
        $ref->setValue($service, $org);

        $method = new ReflectionMethod(QuickBooksService::class, 'prepareInvoicePayload');
        $method->setAccessible(true);
        $payload = $method->invoke($service, $ds, $invoice, $org, '66');

        // Must use user-defined TaxCode '5', NOT system 'TAX' or 'CustomSalesTax'
        $this->assertSame('5', $payload['TxnTaxDetail']['TxnTaxCodeRef']['value']);
        $this->assertSame('GST_PST_18', $payload['TxnTaxDetail']['TxnTaxCodeRef']['name']);
        $this->assertSame(63.62, $payload['TxnTaxDetail']['TotalTax']);
        $this->assertArrayNotHasKey('TaxLine', $payload['TxnTaxDetail']);

        // Service lines get 'TAX', discount is native, and NO Sales Tax product line is appended
        $this->assertCount(3, $payload['Line']);
        $this->assertSame('TAX', $payload['Line'][0]->SalesItemLineDetail->TaxCodeRef->value);
        $this->assertSame('TAX', $payload['Line'][1]->SalesItemLineDetail->TaxCodeRef->value);
        $this->assertSame('DiscountLineDetail', $payload['Line'][2]->DetailType);
    }

    public function test_us_company_with_sales_tax_disabled_adds_reusable_sales_tax_line_so_total_matches(): void
    {
        $org = new Organization(['qb_realm_id' => 'realm-us-off']);
        $org->id = 12;
        $invoice = $this->buildSampleInvoice($org);

        $ds = $this->makeStubDataService('US', false);

        Cache::put('qb_item_verified_realm-us-off_1_55', true, 3600);
        Cache::put('qb_item_verified_realm-us-off_2_57', true, 3600);

        $service = new QuickBooksService();
        $ref = new \ReflectionProperty(QuickBooksService::class, 'organization');
        $ref->setAccessible(true);
        $ref->setValue($service, $org);

        $method = new ReflectionMethod(QuickBooksService::class, 'prepareInvoicePayload');
        $method->setAccessible(true);
        $payload = $method->invoke($service, $ds, $invoice, $org, '66');

        // Must omit TxnTaxDetail and GlobalTaxCalculation (QBO strips them when UsingSalesTax=false)
        $this->assertArrayNotHasKey('TxnTaxDetail', $payload);
        $this->assertArrayNotHasKey('GlobalTaxCalculation', $payload);

        // 2 services + 1 Sales Tax line + 1 Discount line; total math = 250 + 263 + 63.62 - 76.95 = 499.67
        $this->assertCount(4, $payload['Line']);
        $this->assertSame('NON', $payload['Line'][0]->SalesItemLineDetail->TaxCodeRef->value);
        $this->assertSame('NON', $payload['Line'][1]->SalesItemLineDetail->TaxCodeRef->value);
        $this->assertSame('SalesItemLineDetail', $payload['Line'][2]->DetailType);
        $this->assertSame('99', $payload['Line'][2]->SalesItemLineDetail->ItemRef->value);
        $this->assertSame('NON', $payload['Line'][2]->SalesItemLineDetail->TaxCodeRef->value);
        $this->assertSame(63.62, $payload['Line'][2]->Amount);
        $this->assertSame('GST (11%): $47.97 | PST (7%): $15.65', $payload['Line'][2]->Description);
        $this->assertSame('DiscountLineDetail', $payload['Line'][3]->DetailType);
        $this->assertSame(76.95, $payload['Line'][3]->Amount);

        $netTotal = $payload['Line'][0]->Amount + $payload['Line'][1]->Amount + $payload['Line'][2]->Amount - $payload['Line'][3]->Amount;
        $this->assertEqualsWithDelta(499.67, $netTotal, 0.001);
    }

    public function test_company_capabilities_never_caches_timeouts_or_failures(): void
    {
        $org = new Organization(['qb_realm_id' => 'realm-timeout']);
        $org->id = 13;

        $failingDs = new class {
            public function getCompanyInfo()
            {
                throw new \RuntimeException('cURL error during making API call. cURL Error Number:[28]');
            }
            public function getCompanyPreferences()
            {
                return null;
            }
        };

        $service = new QuickBooksService();
        $method = new ReflectionMethod(QuickBooksService::class, 'getCompanyCapabilities');
        $method->setAccessible(true);
        $method->invoke($service, $failingDs, $org);

        $this->assertNull(Cache::get('qb_is_us_company_13_realm-timeout'));
        $this->assertNull(Cache::get('qb_using_sales_tax_13_realm-timeout'));
    }

    public function test_clear_company_metadata_cache_removes_all_realm_and_legacy_keys(): void
    {
        $org = new Organization(['qb_realm_id' => 'realm-old']);
        $org->id = 3;
        Cache::put('qb_is_us_company_3', false, 3600);
        Cache::put('qb_is_us_company_3_realm-old', true, 3600);
        Cache::put('qb_using_sales_tax_3_realm-old', false, 3600);
        Cache::put('qb_tax_codes_3_realm-old', ['x'], 3600);

        $service = new QuickBooksService();
        $service->clearCompanyMetadataCache($org);

        $this->assertNull(Cache::get('qb_is_us_company_3'));
        $this->assertNull(Cache::get('qb_is_us_company_3_realm-old'));
        $this->assertNull(Cache::get('qb_using_sales_tax_3_realm-old'));
        $this->assertNull(Cache::get('qb_tax_codes_3_realm-old'));
    }

    public function test_rebuild_and_update_skips_api_update_when_total_and_tax_mode_already_match(): void
    {
        $org = new Organization(['qb_realm_id' => 'realm-skip-update']);
        $org->id = 14;
        $invoice = $this->buildSampleInvoice($org);
        $invoice->quickbooks_invoice_id = '508';

        $ds = new class {
            public bool $updateCalled = false;

            public function getCompanyInfo()
            {
                return (object)['Country' => 'US', 'CompanyName' => 'Test Co'];
            }
            public function getCompanyPreferences()
            {
                return (object)['TaxPrefs' => (object)['UsingSalesTax' => false]];
            }
            public function FindById($entity, $id)
            {
                return (object)[
                    'Id' => '508',
                    'TotalAmt' => 499.67,
                    'Balance' => 235.88,
                    'TxnTaxDetail' => null,
                ];
            }
            public function Update($obj)
            {
                $this->updateCalled = true;
                return $obj;
            }
        };

        $service = new QuickBooksService();
        $method = new ReflectionMethod(QuickBooksService::class, 'rebuildAndUpdateQBInvoice');
        $method->setAccessible(true);
        $result = $method->invoke($service, $ds, $invoice, $org);

        $this->assertFalse($ds->updateCalled);
        $this->assertSame('508', $result->Id);
    }

    public function test_retry_single_invokes_sync_even_when_invoice_already_has_qb_id(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection('sqlite');
        foreach (['qb_sync_logs', 'invoice_items', 'order_services', 'services', 'invoices', 'orders', 'properties', 'organizations', 'agents'] as $tbl) {
            $schema->dropIfExists($tbl);
        }
        $schema->create('organizations', fn($t) => [$t->id(), $t->uuid('uuid')->nullable(), $t->timestamps()]);
        $schema->create('agents', fn($t) => [$t->id(), $t->uuid('uuid')->nullable(), $t->timestamps()]);
        $schema->create('properties', fn($t) => [$t->id(), $t->uuid('uuid')->nullable(), $t->timestamps()]);
        $schema->create('services', fn($t) => [$t->id(), $t->uuid('uuid')->nullable(), $t->timestamps()]);
        $schema->create('orders', function ($t) {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->timestamps();
        });
        $schema->create('order_services', function ($t) {
            $t->id();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('service_id')->nullable();
            $t->timestamps();
        });
        $schema->create('invoices', function ($t) {
            $t->id();
            $t->uuid('uuid');
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->string('invoice_number')->nullable();
            $t->string('status')->default('paid');
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('paid_amount', 12, 2)->default(0);
            $t->string('quickbooks_invoice_id')->nullable();
            $t->timestamps();
        });
        $schema->create('invoice_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->unsignedBigInteger('order_service_id')->nullable();
            $t->timestamps();
        });
        $schema->create('qb_sync_logs', function ($t) {
            $t->id();
            $t->uuid('uuid');
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id');
            $t->string('qb_entity_id')->nullable();
            $t->string('qb_doc_number')->nullable();
            $t->string('action');
            $t->string('status')->default('pending');
            $t->text('error_message')->nullable();
            $t->string('error_code')->nullable();
            $t->integer('attempts')->default(0);
            $t->string('request_id')->nullable();
            $t->json('payload')->nullable();
            $t->json('response')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });

        $invoice = Invoice::create([
            'uuid' => (string)\Illuminate\Support\Str::uuid(),
            'organization_id' => 3,
            'order_id' => 470,
            'invoice_number' => 'INV-TEST-1',
            'status' => 'paid',
            'total' => 499.67,
            'paid_amount' => 499.67,
            'quickbooks_invoice_id' => '508',
        ]);

        $log = \App\Models\QbSyncLog::create([
            'uuid' => (string)\Illuminate\Support\Str::uuid(),
            'organization_id' => 3,
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'action' => 'update',
            'status' => 'failed',
            'error_message' => 'cURL error 28',
        ]);

        $mockQb = $this->createMock(QuickBooksService::class);
        $mockQb->expects($this->once())
            ->method('syncInvoiceToQB')
            ->with($this->callback(fn($inv) => (int)$inv->id === (int)$invoice->id))
            ->willReturn(['invoice_id' => '508', 'payment_id' => '510', 'doc_number' => 'INV-TEST-1']);

        app()->instance('current_organization_id', 3);
        $controller = new \App\Http\Controllers\Api\QbSyncLogController();
        $req = \Illuminate\Http\Request::create("/api/qb-sync-logs/{$log->uuid}/retry", 'POST');
        $res = $controller->retrySingle($req, $log->uuid, $mockQb);

        $this->assertSame(200, $res->getStatusCode());
        $payload = json_decode($res->getContent(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('success', $payload['status']);
    }
}
