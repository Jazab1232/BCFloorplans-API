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
        if (Schema::hasTable('product_options') && !Schema::hasColumn('product_options', 'sort_order')) {
            Schema::table('product_options', function (Blueprint $table) {
                $table->integer('sort_order')->default(0)->after('amount');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('product_options') && Schema::hasColumn('product_options', 'sort_order')) {
            Schema::table('product_options', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
