<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tablesToScope = [
            'discounts',
            'email_templates',
            'signatures',
            'audio_files'
        ];

        foreach ($tablesToScope as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
                });
            }
        }

        // Backfill existing data to the default organization
        $defaultOrgId = DB::table('organizations')
            ->where('uuid', 'fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a')
            ->value('id');

        if ($defaultOrgId) {
            foreach ($tablesToScope as $tableName) {
                if (Schema::hasTable($tableName)) {
                    DB::table($tableName)->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tablesToScope = [
            'discounts',
            'email_templates',
            'signatures',
            'audio_files'
        ];

        foreach ($tablesToScope as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign([$tableName . '_organization_id_foreign']);
                    $table->dropColumn('organization_id');
                });
            }
        }
    }
};
