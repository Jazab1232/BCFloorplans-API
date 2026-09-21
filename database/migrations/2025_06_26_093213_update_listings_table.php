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
        Schema::table('properties', function (Blueprint $table) {
            // $table->dropForeign(['user_id']);
            // $table->dropColumn('user_id');
            $table->foreignId('agent_id')->nullable()->constrained('agents')->onDelete('cascade');
        });

        $defaultAgentId = DB::table('agents')->value('id');
        if (!$defaultAgentId) {
            throw new Exception('No agent exists to assign as default.');
        }

        DB::table('properties')->whereNull('agent_id')->update([
            'agent_id' => $defaultAgentId
        ]);

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn('agent_id');
            // $table->foreignId('user_id')->constrained()->onDelete('cascade');
        });
    }
};
