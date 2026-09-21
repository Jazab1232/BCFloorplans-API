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
        Schema::table('tour_files', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_complimentary');
        });

        Schema::table('tour_links', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_agent_approved');
        });

        Schema::table('tour_snapshots', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_agent_approved');
        });

        Schema::table('feature_sheet_images', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('uploaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tour_files', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });

        Schema::table('tour_links', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });

        Schema::table('tour_snapshots', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });

        Schema::table('feature_sheet_images', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
