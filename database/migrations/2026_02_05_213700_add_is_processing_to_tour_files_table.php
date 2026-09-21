<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tour_files', function (Blueprint $table) {
            $table->boolean('is_processing')->default(false)->after('is_agent_approved');
            $table->json('variants')->nullable()->after('is_processing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_files', function (Blueprint $table) {
            $table->dropColumn(['is_processing', 'variants']);
        });
    }
};
