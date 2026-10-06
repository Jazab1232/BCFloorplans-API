<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BYO Stripe — Option A: "Bring Your Own Stripe Keys"
 *
 * Each white-label organization stores their own Stripe credentials.
 * When an agent pays, money flows directly into that org's Stripe account.
 * Tojuco is never a financial intermediary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Publishable key (safe to expose to front-end if needed, but stored server-side)
            $table->string('stripe_publishable_key')->nullable()->after('qb_refresh_expires_at');

            // Secret key — used server-side to authenticate Stripe API calls
            $table->text('stripe_secret_key')->nullable()->after('stripe_publishable_key');

            // Webhook signing secret — used to verify incoming Stripe webhook payloads
            $table->string('stripe_webhook_secret')->nullable()->after('stripe_secret_key');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_publishable_key',
                'stripe_secret_key',
                'stripe_webhook_secret',
            ]);
        });
    }
};
