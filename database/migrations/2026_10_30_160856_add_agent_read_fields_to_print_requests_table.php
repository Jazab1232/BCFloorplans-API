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
        Schema::table('print_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('print_requests', 'agent_read')) {
                $table->boolean('agent_read')->default(true)->after('read_at');
            }
            if (!Schema::hasColumn('print_requests', 'agent_read_at')) {
                $table->timestamp('agent_read_at')->nullable()->after('agent_read');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_requests', function (Blueprint $table) {
            if (Schema::hasColumn('print_requests', 'agent_read')) {
                $table->dropColumn('agent_read');
            }
            if (Schema::hasColumn('print_requests', 'agent_read_at')) {
                $table->dropColumn('agent_read_at');
            }
        });
    }
};
