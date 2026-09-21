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
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                if (!Schema::hasColumn('packages', 'organization_id')) {
                    $table->foreignId('organization_id')->nullable()->after('uuid')->constrained('organizations')->nullOnDelete();
                }
            });

            // Assign existing packages to Organization UUID fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a
            $org = \Illuminate\Support\Facades\DB::table('organizations')
                ->where('uuid', 'fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a')
                ->first();
            if ($org) {
                \Illuminate\Support\Facades\DB::table('packages')
                    ->whereNull('organization_id')
                    ->update(['organization_id' => $org->id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                if (Schema::hasColumn('packages', 'organization_id')) {
                    $table->dropForeign(['organization_id']);
                    $table->dropColumn('organization_id');
                }
            });
        }
    }
};
