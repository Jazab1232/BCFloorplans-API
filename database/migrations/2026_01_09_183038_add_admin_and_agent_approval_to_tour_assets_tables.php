<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tour_files', function (Blueprint $table) {
            if (!Schema::hasColumn('tour_files', 'is_admin_approved')) {
            $table->boolean('is_admin_approved')->default(false)->after('id');
            $table->boolean('is_agent_approved')->default(false)->after('is_admin_approved');
            }
        });
        Schema::table('tour_links', function (Blueprint $table) {
            if (!Schema::hasColumn('tour_links', 'is_admin_approved')) {
            $table->boolean('is_admin_approved')->default(false)->after('id');
            $table->boolean('is_agent_approved')->default(false)->after('is_admin_approved');
            }
        });

        Schema::table('tour_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('tour_snapshots', 'is_admin_approved')) {
            $table->boolean('is_admin_approved')->default(false)->after('id');
            $table->boolean('is_agent_approved')->default(false)->after('is_admin_approved');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tour_files', function (Blueprint $table) {
            if (Schema::hasColumn('tour_files', 'is_admin_approved')) {
            $table->dropColumn(['is_admin_approved', 'is_agent_approved']);
            }
        });

        Schema::table('tour_links', function (Blueprint $table) {
            if (Schema::hasColumn('tour_links', 'is_admin_approved')) {
            $table->dropColumn(['is_admin_approved', 'is_agent_approved']);
            }
        });

        Schema::table('tour_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('tour_snapshots', 'is_admin_approved')) {
            $table->dropColumn(['is_admin_approved', 'is_agent_approved']);
            }
        });
    }
};
