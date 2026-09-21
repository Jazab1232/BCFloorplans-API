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
    Schema::table('vendor_payments', function (Blueprint $table) {
        $table->string('order_service_uuid')->nullable()->change();
        $table->json('order_service_uuids')->nullable()->after('order_service_uuid');
        $table->boolean('is_bulk')->default(false)->after('order_service_uuids');
    });
}

public function down(): void
{
    Schema::table('vendor_payments', function (Blueprint $table) {
        $table->string('order_service_uuid')->nullable(false)->change();
        $table->dropColumn(['order_service_uuids', 'is_bulk']);
    });
}

};
