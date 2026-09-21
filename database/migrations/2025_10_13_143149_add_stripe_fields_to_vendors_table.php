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
        $table->string('stripe_account_id')->nullable()->after('status');
        $table->string('stripe_account_email')->nullable()->after('stripe_account_id');
    });
}

public function down(): void
{
    Schema::table('vendors', function (Blueprint $table) {
        $table->dropColumn(['stripe_account_id', 'stripe_account_email']);
    });
}

};
