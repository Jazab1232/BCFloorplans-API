<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill organization_id on agent-uploaded audio files that were uploaded
     * before the fix that explicitly set organization_id on agent uploads.
     *
     * These rows have agent_id set but organization_id = null, meaning they
     * accidentally match the "global/default" audio clause in queries and
     * would be returned for every agent.
     */
    public function up(): void
    {
        // For every audio file that has an agent_id but no organization_id,
        // look up the agent's organization and set it.
        DB::statement("
            UPDATE audio_files
            SET organization_id = a.organization_id
            FROM agents a
            WHERE audio_files.agent_id = a.id
              AND audio_files.agent_id IS NOT NULL
              AND audio_files.organization_id IS NULL
              AND a.organization_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        // Cannot safely reverse — we'd need to know which rows were changed.
        // This is a data fix, not a structural change.
    }
};
