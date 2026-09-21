<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_services', function (Blueprint $table) {
            $table->uuid('vendor_id')->nullable()->after('service_id');
            $table->boolean('is_completed')->default(false)->after('custom');
            $table->boolean('vendor_paid')->default(false)->after('is_completed');
            $table->timestamp('vendor_paid_at')->nullable()->after('vendor_paid');

            // Optional foreign key (only if vendors.uuid is primary)
            // $table->foreign('vendor_id')->references('uuid')->on('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_services', function (Blueprint $table) {
            $table->dropColumn(['vendor_id', 'is_completed', 'vendor_paid', 'vendor_paid_at']);
        });
    }
};
