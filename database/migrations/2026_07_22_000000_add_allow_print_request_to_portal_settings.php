<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add allow_print_request to the existing global portal_settings record.
     * The original seed migration (2026_06_16_233000) already ran on staging
     * without this field, so we patch it here rather than modifying the seed.
     */
    public function up(): void
    {
        $row = DB::table('settings')
            ->where('key', 'portal_settings')
            ->whereNull('org_id')
            ->first();

        if ($row) {
            $value = json_decode($row->value, true) ?? [];

            // Only patch if the field is not already present
            if (!array_key_exists('allow_print_request', $value)) {
                $value['allow_print_request'] = true;

                DB::table('settings')
                    ->where('key', 'portal_settings')
                    ->whereNull('org_id')
                    ->update([
                        'value'      => json_encode($value),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        $row = DB::table('settings')
            ->where('key', 'portal_settings')
            ->whereNull('org_id')
            ->first();

        if ($row) {
            $value = json_decode($row->value, true) ?? [];
            unset($value['allow_print_request']);

            DB::table('settings')
                ->where('key', 'portal_settings')
                ->whereNull('org_id')
                ->update([
                    'value'      => json_encode($value),
                    'updated_at' => now(),
                ]);
        }
    }
};
