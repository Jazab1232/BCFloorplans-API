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
        if (Schema::hasTable('qb_sync_logs') && !Schema::hasColumn('qb_sync_logs', 'organization_id')) {
            Schema::table('qb_sync_logs', function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->after('uuid')->constrained()->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('qb_sync_logs') && Schema::hasColumn('qb_sync_logs', 'organization_id')) {
            Schema::table('qb_sync_logs', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
                $table->dropColumn('organization_id');
            });
        }
    }
};
