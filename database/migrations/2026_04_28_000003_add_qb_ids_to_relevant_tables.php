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
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('quickbooks_vendor_id')->nullable()->after('stripe_account_id');
            $table->timestamp('quickbooks_synced_at')->nullable()->after('quickbooks_vendor_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('quickbooks_invoice_id')->nullable()->after('split_details');
            $table->timestamp('quickbooks_synced_at')->nullable()->after('quickbooks_invoice_id');
            $table->index('quickbooks_invoice_id');
        });

        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->string('quickbooks_bill_id')->nullable()->after('stripe_transfer_id');
            $table->timestamp('quickbooks_synced_at')->nullable()->after('quickbooks_bill_id');
            $table->index('quickbooks_bill_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table) {
            $table->dropIndex(['quickbooks_bill_id']);
            $table->dropColumn(['quickbooks_bill_id', 'quickbooks_synced_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['quickbooks_invoice_id']);
            $table->dropColumn(['quickbooks_invoice_id', 'quickbooks_synced_at']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['quickbooks_vendor_id', 'quickbooks_synced_at']);
        });
    }
};
