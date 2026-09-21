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
        $tourSettings = [
            'music_enabled' => true,
            'default_song' => 'tell-me-what',
            'transition_effect' => ['kenburns'],
            'layout_option' => 'standard',
            'video_slideshow_enabled' => true,
            'letterbox_correction' => true,
            'aspect_ratio' => '16:9',
            'autoplay_enabled' => true,
            'allow_print_download' => true,
            'allow_client_upload' => true,
            'require_payment_before_download' => false,
        ];

        // Check if global tour_settings already exist to avoid duplicate/overwrite if re-run
        $exists = DB::table('settings')
            ->where('key', 'tour_settings')
            ->whereNull('org_id')
            ->exists();

        if (!$exists) {
            DB::table('settings')->insert([
                'uuid' => (string) Str::uuid(),
                'key' => 'tour_settings',
                'value' => json_encode($tourSettings),
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
            ->where('key', 'tour_settings')
            ->whereNull('org_id')
            ->delete();
    }
};
