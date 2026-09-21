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
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->decimal('tax_rate', 8, 2)->default(0.00)->after('tax_amount');
            $table->string('tax_type')->nullable()->after('tax_rate');
            $table->string('tax_number')->nullable()->after('tax_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'tax_rate',
                'tax_type',
                'tax_number',
            ]);
        });
    }
};
