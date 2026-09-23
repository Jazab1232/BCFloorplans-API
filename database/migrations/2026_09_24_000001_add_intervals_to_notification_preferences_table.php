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
        if (Schema::hasTable('notification_preferences') && !Schema::hasColumn('notification_preferences', 'intervals')) {
            Schema::table('notification_preferences', function (Blueprint $table) {
                $table->json('intervals')->nullable()->after('email_enabled');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('notification_preferences') && Schema::hasColumn('notification_preferences', 'intervals')) {
            Schema::table('notification_preferences', function (Blueprint $table) {
                $table->dropColumn('intervals');
            });
        }
    }
};
