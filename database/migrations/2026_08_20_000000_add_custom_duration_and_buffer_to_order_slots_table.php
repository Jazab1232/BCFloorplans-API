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
        Schema::table('order_slots', function (Blueprint $table) {
            if (!Schema::hasColumn('order_slots', 'custom_duration')) {
                $table->integer('custom_duration')->nullable()->after('end_time')->comment('Custom duration override in minutes');
            }
            if (!Schema::hasColumn('order_slots', 'custom_end_time')) {
                $table->string('custom_end_time', 50)->nullable()->after('custom_duration');
            }
            if (!Schema::hasColumn('order_slots', 'buffer_minutes')) {
                $table->integer('buffer_minutes')->nullable()->default(0)->after('custom_end_time')->comment('Buffer/travel time in minutes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_slots', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('order_slots', 'custom_duration')) {
                $columnsToDrop[] = 'custom_duration';
            }
            if (Schema::hasColumn('order_slots', 'custom_end_time')) {
                $columnsToDrop[] = 'custom_end_time';
            }
            if (Schema::hasColumn('order_slots', 'buffer_minutes')) {
                $columnsToDrop[] = 'buffer_minutes';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
