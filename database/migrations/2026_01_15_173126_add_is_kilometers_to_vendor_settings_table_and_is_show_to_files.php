<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('vendor_settings', 'is_kilometers')) {
            $table->boolean('is_kilometers')
                  ->default(true)
                  ->after('payment_per_km');
            }

        });

        Schema::table('tour_files', function (Blueprint $table) {
            if (!Schema::hasColumn('tour_files', 'is_show')) {
            $table->boolean('is_show')
                  ->default(false)
                  ->after('file_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendor_settings', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_settings', 'is_kilometers')){
            $table->dropColumn('is_kilometers');
            }
           
        });
        Schema::table('tour_files', function (Blueprint $table) {
            if (Schema::hasColumn('tour_files', 'is_show')) {
            $table->dropColumn('is_show');
            }
        });
    }

};
