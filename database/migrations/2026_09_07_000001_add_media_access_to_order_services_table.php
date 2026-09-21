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
        if (Schema::hasTable('order_services')) {
            Schema::table('order_services', function (Blueprint $table) {
                if (!Schema::hasColumn('order_services', 'media_access')) {
                    $table->boolean('media_access')->nullable()->default(null)->after('payment_status');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('order_services')) {
            Schema::table('order_services', function (Blueprint $table) {
                if (Schema::hasColumn('order_services', 'media_access')) {
                    $table->dropColumn('media_access');
                }
            });
        }
    }
};
