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
        if (Schema::hasTable('global_tour_settings') && !Schema::hasColumn('global_tour_settings', 'organization_id')) {
            Schema::table('global_tour_settings', function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('global_tour_settings') && Schema::hasColumn('global_tour_settings', 'organization_id')) {
            Schema::table('global_tour_settings', function (Blueprint $table) {
                $table->dropForeign(['global_tour_settings_organization_id_foreign']);
                $table->dropColumn('organization_id');
            });
        }
    }
};
