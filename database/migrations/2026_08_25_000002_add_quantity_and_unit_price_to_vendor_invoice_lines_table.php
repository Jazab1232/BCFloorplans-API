<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendor_invoice_lines', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->default(1.00)->after('description');
            $table->decimal('unit_price', 15, 2)->default(0.00)->after('quantity');
            $table->boolean('is_taxable')->default(true)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_invoice_lines', function (Blueprint $table) {
            $table->dropColumn([
                'quantity',
                'unit_price',
                'is_taxable',
            ]);
        });
    }
};
