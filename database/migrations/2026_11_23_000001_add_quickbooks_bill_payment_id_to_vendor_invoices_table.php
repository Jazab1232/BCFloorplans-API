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
            if (!Schema::hasColumn('vendor_invoices', 'quickbooks_bill_payment_id')) {
                $table->string('quickbooks_bill_payment_id')->nullable()->after('quickbooks_bill_id');
                $table->index('quickbooks_bill_payment_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_invoices', 'quickbooks_bill_payment_id')) {
                $table->dropIndex(['quickbooks_bill_payment_id']);
                $table->dropColumn('quickbooks_bill_payment_id');
            }
        });
    }
};
