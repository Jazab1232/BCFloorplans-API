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
        Schema::table('service_categories', function (Blueprint $table) {
            $table->boolean('add_ons')->default(false)->after('duration');
        });

        Schema::table('product_options', function (Blueprint $table) {
            $table->decimal('min_price', 8, 2)->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn('add_ons');
        });

        Schema::table('product_options', function (Blueprint $table) {
            $table->dropColumn('min_price');
        });
    }
};
