<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create organization_services table
        Schema::create('organization_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_id')->constrained()->onDelete('cascade');
            $table->boolean('is_enabled')->default(true);
            $table->decimal('price_override', 10, 2)->nullable();
            $table->json('options_override')->nullable();
            $table->timestamps();
            
            $table->unique(['organization_id', 'service_id']);
        });

        // 2. Add organization_id to all relevant tables
        $tablesToScope = [
            'orders',
            'properties',
            'invoices',
            'vendor_invoices',
            'feature_sheets',
            'print_requests',
            'booking_reminders',
            'qb_sync_logs',
            'notifications',
            'sub_accounts'
        ];

        foreach ($tablesToScope as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
                });
            }
        }

        // 3. Backfill existing data to the default organization
        $defaultOrgId = DB::table('organizations')
            ->where('uuid', 'fbd6e3a5-4b2c-4de1-ab73-e677b54c4b8a')
            ->value('id');

        if ($defaultOrgId) {
            foreach ($tablesToScope as $tableName) {
                if (Schema::hasTable($tableName)) {
                    DB::table($tableName)->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
                }
            }
            
            // Also backfill existing users/agents/vendors who might have NULL org_id
            DB::table('users')->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
            DB::table('agents')->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
            DB::table('vendors')->whereNull('organization_id')->update(['organization_id' => $defaultOrgId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_services');

        $tablesToScope = [
            'orders',
            'properties',
            'invoices',
            'vendor_invoices',
            'feature_sheets',
            'print_requests',
            'booking_reminders',
            'qb_sync_logs',
            'notifications',
            'sub_accounts'
        ];

        foreach ($tablesToScope as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign([$tableName . '_organization_id_foreign']);
                    $table->dropColumn('organization_id');
                });
            }
        }
    }
};
