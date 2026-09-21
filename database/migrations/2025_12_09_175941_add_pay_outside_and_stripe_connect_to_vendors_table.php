<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('pay_outside')
                  ->default(false)
                  ->after('status'); 

            $table->boolean('stripe_connect')
                  ->default(false)
                  ->after('pay_outside');
            $table->dropColumn('stripe_account_email');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['pay_outside', 'stripe_connect']);
            $table->string('stripe_account_email')->nullable()->after('stripe_account_id');
        });
    }
};
