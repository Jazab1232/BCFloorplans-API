<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_settings', 'next_booking_slot_only')) {
                $table->boolean('next_booking_slot_only')
                      ->default(false)
                      ->after('force_service_area');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendor_settings', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_settings', 'next_booking_slot_only')) {
                $table->dropColumn('next_booking_slot_only');
            }
        });
    }
};
