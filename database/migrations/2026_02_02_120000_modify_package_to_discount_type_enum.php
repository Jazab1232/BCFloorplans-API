<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop existing constraint if it exists
        DB::statement("ALTER TABLE order_totals DROP CONSTRAINT IF EXISTS order_totals_discount_type_check");
        
        // Add the new constraint with 'package' included
        DB::statement("ALTER TABLE order_totals ADD CONSTRAINT order_totals_discount_type_check CHECK (discount_type IN ('package', 'quantity', 'code', 'manual'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the current constraint
        DB::statement('ALTER TABLE order_totals DROP CONSTRAINT IF EXISTS order_totals_discount_type_check');
        
        // Restore original constraint without 'package'
        DB::statement("ALTER TABLE order_totals ADD CONSTRAINT order_totals_discount_type_check CHECK (discount_type IN ('quantity', 'code', 'manual'))");
    }
};