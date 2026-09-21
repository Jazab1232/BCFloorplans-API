<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add org_id column first
        Schema::table('settings', function (Blueprint $table) {
            if (!Schema::hasColumn('settings', 'org_id')) {
                $table->uuid('org_id')->nullable()->after('key');
            }
        });

        // 2. Drop UNIQUE constraint on key (Postgres-safe)
        DB::statement(
            'ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_key_unique'
        );

        // 3. Add composite unique + FK
        Schema::table('settings', function (Blueprint $table) {
            $table->unique(['key', 'org_id'], 'settings_key_org_unique');

            $table->foreign('org_id')
                ->references('uuid')
                ->on('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // 1. Drop FK & composite unique
        Schema::table('settings', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropUnique('settings_key_org_unique');
        });

        // 2. Restore original unique constraint on key
        DB::statement(
            'ALTER TABLE settings ADD CONSTRAINT settings_key_unique UNIQUE (key)'
        );

        // 3. Remove org_id column
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('org_id');
        });
    }
};
