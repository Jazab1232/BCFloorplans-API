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
            $table->json('vendor_details')->nullable()->after('notes');
            $table->json('org_details')->nullable()->after('vendor_details');
            $table->json('tax_details')->nullable()->after('org_details');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_details',
                'org_details',
                'tax_details',
            ]);
        });
    }
};
