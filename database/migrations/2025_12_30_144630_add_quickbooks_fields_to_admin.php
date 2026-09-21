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
        Schema::table('users', function (Blueprint $table) {
            $table->string('qb_realm_id')->nullable();
            $table->text('qb_access_token')->nullable();
            $table->text('qb_refresh_token')->nullable();
            $table->timestamp('qb_access_expires_at')->nullable();
            $table->timestamp('qb_refresh_expires_at')->nullable();
        });

        Schema::table('agent_payments', function (Blueprint $table) {
            $table->string('quickbooks_invoice_id')->nullable()->after('stripe_receipt_url');
            $table->string('quickbooks_payment_id')->nullable()->after('quickbooks_invoice_id');
            $table->timestamp('quickbooks_synced_at')->nullable()->after('quickbooks_payment_id');
            
            $table->index('quickbooks_invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'qb_realm_id',
                'qb_access_token',
                'qb_refresh_token',
                'qb_access_expires_at',
                'qb_refresh_expires_at'
            ]);
        });

        Schema::table('agent_payments', function (Blueprint $table) {
            $table->dropIndex(['quickbooks_invoice_id']);
            $table->dropColumn([
                'quickbooks_invoice_id',
                'quickbooks_payment_id',
                'quickbooks_synced_at'
            ]);
        });
    }
};