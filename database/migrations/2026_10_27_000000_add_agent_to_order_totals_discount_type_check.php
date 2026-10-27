<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE order_totals DROP CONSTRAINT IF EXISTS discount_type_check");
        DB::statement("ALTER TABLE order_totals DROP CONSTRAINT IF EXISTS order_totals_discount_type_check");

        DB::statement("ALTER TABLE order_totals ADD CONSTRAINT order_totals_discount_type_check CHECK (discount_type IN ('package', 'quantity', 'code', 'manual', 'agent'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE order_totals DROP CONSTRAINT IF EXISTS order_totals_discount_type_check");
        DB::statement("ALTER TABLE order_totals ADD CONSTRAINT order_totals_discount_type_check CHECK (discount_type IN ('package', 'quantity', 'code', 'manual'))");
    }
};
