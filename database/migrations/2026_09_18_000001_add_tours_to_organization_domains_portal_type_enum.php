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
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE organization_domains DROP CONSTRAINT IF EXISTS organization_domains_portal_type_check");
            DB::statement("ALTER TABLE organization_domains ADD CONSTRAINT organization_domains_portal_type_check CHECK (portal_type::text IN ('admin', 'agent', 'vendor', 'tours'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE organization_domains MODIFY COLUMN portal_type ENUM('admin', 'agent', 'vendor', 'tours') NOT NULL");
        } else {
            Schema::table('organization_domains', function (Blueprint $table) {
                $table->string('portal_type')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE organization_domains DROP CONSTRAINT IF EXISTS organization_domains_portal_type_check");
            DB::statement("ALTER TABLE organization_domains ADD CONSTRAINT organization_domains_portal_type_check CHECK (portal_type::text IN ('admin', 'agent', 'vendor'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE organization_domains MODIFY COLUMN portal_type ENUM('admin', 'agent', 'vendor') NOT NULL");
        } else {
            Schema::table('organization_domains', function (Blueprint $table) {
                $table->enum('portal_type', ['admin', 'agent', 'vendor'])->change();
            });
        }
    }
};
