<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $portalSettings = [
            'show_org_details_on_empty_schedule' => false,
            'disable_next_day_booking' => false,
            'booking_cutoff_time' => '17:00',
            'allow_print_request' => true,
        ];

        // Check if global portal_settings already exist to avoid duplicate/overwrite if re-run
        $exists = DB::table('settings')
            ->where('key', 'portal_settings')
            ->whereNull('org_id')
            ->exists();

        if (!$exists) {
            DB::table('settings')->insert([
                'uuid' => (string) Str::uuid(),
                'key' => 'portal_settings',
                'value' => json_encode($portalSettings),
                'org_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'portal_settings')
            ->whereNull('org_id')
            ->delete();
    }
};
