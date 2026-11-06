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
        Schema::table('order_services', function (Blueprint $table) {
            if (!Schema::hasColumn('order_services', 'status')) {
                $table->string('status')->default('active')->after('is_completed')->index();
            }
        });

        Schema::table('order_slots', function (Blueprint $table) {
            if (!Schema::hasColumn('order_slots', 'status')) {
                $table->string('status')->default('booked')->after('date')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_services', function (Blueprint $table) {
            if (Schema::hasColumn('order_services', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::table('order_slots', function (Blueprint $table) {
            if (Schema::hasColumn('order_slots', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
