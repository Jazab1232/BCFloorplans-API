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
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('country');
            }
            if (!Schema::hasColumn('properties', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('properties', 'map_zoom')) {
                $table->unsignedTinyInteger('map_zoom')->nullable()->default(15)->after('longitude');
            }
            if (!Schema::hasColumn('properties', 'map_type')) {
                $table->string('map_type')->nullable()->default('roadmap')->after('map_zoom');
            }
            if (!Schema::hasColumn('properties', 'map_center_lat')) {
                $table->decimal('map_center_lat', 10, 7)->nullable()->after('map_type');
            }
            if (!Schema::hasColumn('properties', 'map_center_lng')) {
                $table->decimal('map_center_lng', 10, 7)->nullable()->after('map_center_lat');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $columns = ['latitude', 'longitude', 'map_zoom', 'map_type', 'map_center_lat', 'map_center_lng'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('properties', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
