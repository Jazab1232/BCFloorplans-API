<?php

namespace Tests\Unit;

use App\Models\VendorServiceOption;
use Tests\TestCase;

class VendorServiceOptionRelationTest extends TestCase
{
    public function test_product_option_relation_uses_option_id_foreign_key(): void
    {
        $model = new VendorServiceOption();

        $this->assertSame('option_id', $model->productOption()->getForeignKeyName());
    }

    public function test_vendor_adjustment_time_is_cast_to_integer(): void
    {
        $model = new VendorServiceOption();
        $casts = $model->getCasts();

        $this->assertArrayHasKey('vendor_adjustment_time', $casts);
        $this->assertSame('integer', $casts['vendor_adjustment_time']);
    }
}
