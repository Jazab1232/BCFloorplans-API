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
        Schema::table('agents', function (Blueprint $table) {
            if (!Schema::hasColumn('agents', 'agent_type')) {
                $table->enum('agent_type', ['standard', 'co_agent'])->default('standard')->after('payment_status');
            }
            if (!Schema::hasColumn('agents', 'parent_agent_id')) {
                $table->foreignId('parent_agent_id')->nullable()->after('agent_type')->constrained('agents')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            if (Schema::hasColumn('agents', 'parent_agent_id')) {
                $table->dropForeign(['parent_agent_id']);
                $table->dropColumn('parent_agent_id');
            }
            if (Schema::hasColumn('agents', 'agent_type')) {
                $table->dropColumn('agent_type');
            }
        });
    }
};
