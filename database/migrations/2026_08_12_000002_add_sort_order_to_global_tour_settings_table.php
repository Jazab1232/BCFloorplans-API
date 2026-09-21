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
        if (Schema::hasTable('global_tour_settings') && !Schema::hasColumn('global_tour_settings', 'sort_order')) {
            Schema::table('global_tour_settings', function (Blueprint $table) {
                $table->integer('sort_order')->default(0)->after('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('global_tour_settings') && Schema::hasColumn('global_tour_settings', 'sort_order')) {
            Schema::table('global_tour_settings', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
