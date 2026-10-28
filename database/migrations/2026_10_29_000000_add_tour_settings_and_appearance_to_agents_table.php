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
        Schema::table('agents', function (Blueprint $table) {
            if (!Schema::hasColumn('agents', 'tour_settings')) {
                $table->json('tour_settings')->nullable()->after('linked_agent_uuids');
            }
            if (!Schema::hasColumn('agents', 'tour_appearance')) {
                $table->json('tour_appearance')->nullable()->after('tour_settings');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            if (Schema::hasColumn('agents', 'tour_appearance')) {
                $table->dropColumn('tour_appearance');
            }
            if (Schema::hasColumn('agents', 'tour_settings')) {
                $table->dropColumn('tour_settings');
            }
        });
    }
};
