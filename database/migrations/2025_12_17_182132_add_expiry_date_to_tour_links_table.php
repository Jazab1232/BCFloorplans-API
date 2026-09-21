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
        Schema::table('tour_links', function (Blueprint $table) {
            if (!Schema::hasColumn('tour_links', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('link');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_links', function (Blueprint $table) {
            if (Schema::hasColumn('tour_links', 'expiry_date')) {
                $table->dropColumn('expiry_date');
            }
        });
    }
};
