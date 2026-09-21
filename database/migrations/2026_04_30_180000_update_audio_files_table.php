<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename table if old name exists
        if (Schema::hasTable('agent_audio_files') && !Schema::hasTable('audio_files')) {
            Schema::rename('agent_audio_files', 'audio_files');
        }

        // 2. Modify table
        Schema::table('audio_files', function (Blueprint $table) {
            // Use explicit constraint names to avoid Postgres auto-naming issues
            
            if (!Schema::hasColumn('audio_files', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('agent_id');
                
                $table->foreign('organization_id', 'audio_files_organization_id_foreign')
                    ->references('id')
                    ->on('organizations')
                    ->onDelete('set null');
                
                $table->index('organization_id', 'audio_files_organization_id_index');
            }

            // Make agent_id nullable
            // Note: If this fails in Postgres, it might be due to existing constraints.
            // We use change() which is supported natively in Laravel 10+
            $table->unsignedBigInteger('agent_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audio_files', function (Blueprint $table) {
            if (Schema::hasColumn('audio_files', 'organization_id')) {
                $table->dropForeign('audio_files_organization_id_foreign');
                $table->dropColumn('organization_id');
            }
            
            $table->unsignedBigInteger('agent_id')->nullable(false)->change();
        });

        if (Schema::hasTable('audio_files') && !Schema::hasTable('agent_audio_files')) {
            Schema::rename('audio_files', 'agent_audio_files');
        }
    }
};
