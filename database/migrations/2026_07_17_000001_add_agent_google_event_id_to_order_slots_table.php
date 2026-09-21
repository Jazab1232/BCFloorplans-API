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
            if (!Schema::hasColumn('order_slots', 'agent_google_event_id')) {
                $table->string('agent_google_event_id')->nullable()->after('google_event_id');
                $table->index('agent_google_event_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_slots', function (Blueprint $table) {
            if (Schema::hasColumn('order_slots', 'agent_google_event_id')) {
                $table->dropColumn('agent_google_event_id');
            }
        });
    }
};
