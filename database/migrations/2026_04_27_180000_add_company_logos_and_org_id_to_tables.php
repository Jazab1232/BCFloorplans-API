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
        // Add organization_id and company_logos to agents table
        Schema::table('agents', function (Blueprint $table) {
            if (!Schema::hasColumn('agents', 'organization_id')) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('organizations')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('agents', 'company_logos')) {
                $table->json('company_logos')->nullable()->after('company_banner');
            }
        });

        // Add company_logos to organizations table
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'company_logos')) {
                $table->json('company_logos')->nullable()->after('postal_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('company_logos');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id', 'company_logos']);
        });
    }
};
