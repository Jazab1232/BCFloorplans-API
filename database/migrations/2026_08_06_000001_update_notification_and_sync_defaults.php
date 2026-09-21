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
        // 1. Update defaults on tables to be true/1 by default
        Schema::table('agents', function (Blueprint $table) {
            if (Schema::hasColumn('agents', 'sync_google_calendar')) {
                $table->boolean('sync_google_calendar')->default(true)->change();
            }
            if (!Schema::hasColumn('agents', 'notification_email')) {
                $table->boolean('notification_email')->default(true)->after('requires_payment');
            } else {
                $table->boolean('notification_email')->default(true)->change();
            }
        });

        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'sync_google_calendar')) {
                $table->boolean('sync_google_calendar')->default(true)->change();
            }
            if (Schema::hasColumn('vendors', 'notification_email')) {
                $table->boolean('notification_email')->default(true)->change();
            }
        });

        Schema::table('sub_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('sub_accounts', 'notification_email')) {
                $table->boolean('notification_email')->default(true)->change();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'notification_email')) {
                $table->boolean('notification_email')->default(true)->after('organization_id');
            } else {
                $table->boolean('notification_email')->default(true)->change();
            }
        });

        // 2. Set all existing records to true/1
        DB::table('agents')->update([
            'sync_google_calendar' => true,
            'notification_email' => true,
        ]);

        DB::table('vendors')->update([
            'sync_google_calendar' => true,
            'notification_email' => true,
        ]);

        DB::table('sub_accounts')->update([
            'notification_email' => true,
        ]);

        DB::table('users')->update([
            'notification_email' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No safe rollback as we cannot distinguish who intentionally toggled false
    }
};
