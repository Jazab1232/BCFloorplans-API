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
        // 1. Find BCF organization
        $bcfOrg = DB::table('organizations')
            ->where('slug', 'bcf')
            ->orWhere('slug', 'bcfloorplans')
            ->orWhere('name', 'BCFloorplans')
            ->orWhere('name', 'BC Floor Plans')
            ->first();

        if ($bcfOrg) {
            $bcfStyles = [
                'admin' => [
                    'pageBg' => '#EFEFEF',
                    'pageText' => '#6D6D6D',
                    'sidebarBg' => '#E4E4E4',
                    'sidebarText' => '#6D6D6D',
                    'sidebarHoverBg' => '#f4f4f5',
                    'sidebarHoverText' => '#6D6D6D',
                    'activeColor' => '#4290E9',
                    'pageTabColor' => '#4290E9',
                    'logo' => '',
                    'logoWidth' => '32',
                ],
                'vendor' => [
                    'pageBg' => '#EFEFEF',
                    'pageText' => '#6D6D6D',
                    'sidebarBg' => '#E4E4E4',
                    'sidebarText' => '#6D6D6D',
                    'sidebarHoverBg' => '#f4f4f5',
                    'sidebarHoverText' => '#6D6D6D',
                    'activeColor' => '#DC9600',
                    'pageTabColor' => '#DC9600',
                    'logo' => '',
                    'logoWidth' => '32',
                ],
                'agent' => [
                    'pageBg' => '#EFEFEF',
                    'pageText' => '#6D6D6D',
                    'sidebarBg' => '#E4E4E4',
                    'sidebarText' => '#6D6D6D',
                    'sidebarHoverBg' => '#f4f4f5',
                    'sidebarHoverText' => '#6D6D6D',
                    'activeColor' => '#6bae41',
                    'pageTabColor' => '#6bae41',
                    'logo' => '',
                    'logoWidth' => '32',
                ],
            ];

            // 2. Check if a setting already exists for BCF
            $existing = DB::table('settings')
                ->where('key', 'white_label_styles')
                ->where('org_id', $bcfOrg->uuid)
                ->first();

            if ($existing) {
                DB::table('settings')
                    ->where('id', $existing->id)
                    ->update([
                        'value' => json_encode($bcfStyles),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('settings')->insert([
                    'uuid' => (string) Str::uuid(),
                    'key' => 'white_label_styles',
                    'value' => json_encode($bcfStyles),
                    'org_id' => $bcfOrg->uuid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $bcfOrg = DB::table('organizations')
            ->where('slug', 'bcf')
            ->orWhere('slug', 'bcfloorplans')
            ->orWhere('name', 'BCFloorplans')
            ->orWhere('name', 'BC Floor Plans')
            ->first();

        if ($bcfOrg) {
            DB::table('settings')
                ->where('key', 'white_label_styles')
                ->where('org_id', $bcfOrg->uuid)
                ->delete();
        }
    }
};
