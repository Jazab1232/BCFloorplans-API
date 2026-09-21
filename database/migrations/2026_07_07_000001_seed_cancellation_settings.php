<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $setting = DB::table('settings')
            ->where('key', 'portal_settings')
            ->whereNull('org_id')
            ->first();

        if ($setting) {
            $value = json_decode($setting->value, true);
            
            // Add cancellation defaults if they don't exist
            $value['cancellation_threshold_hours'] = $value['cancellation_threshold_hours'] ?? 24;
            $value['cancellation_fee_percentage'] = $value['cancellation_fee_percentage'] ?? 25.00;
            $value['allow_cancel_after_threshold'] = $value['allow_cancel_after_threshold'] ?? true;

            DB::table('settings')
                ->where('id', $setting->id)
                ->update([
                    'value' => json_encode($value),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $setting = DB::table('settings')
            ->where('key', 'portal_settings')
            ->whereNull('org_id')
            ->first();

        if ($setting) {
            $value = json_decode($setting->value, true);
            
            unset($value['cancellation_threshold_hours']);
            unset($value['cancellation_fee_percentage']);
            unset($value['allow_cancel_after_threshold']);

            DB::table('settings')
                ->where('id', $setting->id)
                ->update([
                    'value' => json_encode($value),
                    'updated_at' => now(),
                ]);
        }
    }
};
