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
            if (!Schema::hasColumn('agents', 'agent_type')) {
                $table->string('agent_type')->default('master')->after('payment_status');
            }
            if (!Schema::hasColumn('agents', 'linked_agent_uuids')) {
                $table->json('linked_agent_uuids')->nullable()->after('agent_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            if (Schema::hasColumn('agents', 'linked_agent_uuids')) {
                $table->dropColumn('linked_agent_uuids');
            }
            if (Schema::hasColumn('agents', 'agent_type')) {
                $table->dropColumn('agent_type');
            }
        });
    }
};
