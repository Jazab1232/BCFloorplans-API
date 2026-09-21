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
        Schema::table('services', function (Blueprint $table) {
            if(!Schema::hasColumn('services', 'is_travel_required')) {
                $table->boolean('is_travel_required')->default(true)->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if(Schema::hasColumn('services', 'is_travel_required')) {
                $table->dropColumn('is_travel_required');
            }
        });
    }
};
