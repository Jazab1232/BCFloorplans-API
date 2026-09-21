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
        // 1. Add duration fields to services table (Global Master Defaults)
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                if (!Schema::hasColumn('services', 'base_duration_mins')) {
                    $table->unsignedInteger('base_duration_mins')->default(60)->after('vendor_hourly_rate');
                }
                if (!Schema::hasColumn('services', 'base_sq_ft')) {
                    $table->unsignedInteger('base_sq_ft')->default(2000)->after('base_duration_mins');
                }
                if (!Schema::hasColumn('services', 'increment_duration_mins')) {
                    $table->unsignedInteger('increment_duration_mins')->default(30)->after('base_sq_ft');
                }
                if (!Schema::hasColumn('services', 'increment_sq_ft')) {
                    $table->unsignedInteger('increment_sq_ft')->default(1000)->after('increment_duration_mins');
                }
            });
        }

        // 2. Add duration fields to organization_services table (Per-Org Overrides)
        if (Schema::hasTable('organization_services')) {
            Schema::table('organization_services', function (Blueprint $table) {
                if (!Schema::hasColumn('organization_services', 'base_duration_mins')) {
                    $table->unsignedInteger('base_duration_mins')->nullable()->after('pst_enabled');
                }
                if (!Schema::hasColumn('organization_services', 'base_sq_ft')) {
                    $table->unsignedInteger('base_sq_ft')->nullable()->after('base_duration_mins');
                }
                if (!Schema::hasColumn('organization_services', 'increment_duration_mins')) {
                    $table->unsignedInteger('increment_duration_mins')->nullable()->after('base_sq_ft');
                }
                if (!Schema::hasColumn('organization_services', 'increment_sq_ft')) {
                    $table->unsignedInteger('increment_sq_ft')->nullable()->after('increment_duration_mins');
                }
            });
        }

        // 3. Add duration fields to product_options table (Option Overrides)
        if (Schema::hasTable('product_options')) {
            Schema::table('product_options', function (Blueprint $table) {
                if (!Schema::hasColumn('product_options', 'base_duration_mins')) {
                    $table->unsignedInteger('base_duration_mins')->nullable()->after('vendor_hourly_rate');
                }
                if (!Schema::hasColumn('product_options', 'base_sq_ft')) {
                    $table->unsignedInteger('base_sq_ft')->nullable()->after('base_duration_mins');
                }
                if (!Schema::hasColumn('product_options', 'increment_duration_mins')) {
                    $table->unsignedInteger('increment_duration_mins')->nullable()->after('base_sq_ft');
                }
                if (!Schema::hasColumn('product_options', 'increment_sq_ft')) {
                    $table->unsignedInteger('increment_sq_ft')->nullable()->after('increment_duration_mins');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                $cols = array_filter(['base_duration_mins', 'base_sq_ft', 'increment_duration_mins', 'increment_sq_ft'], fn($c) => Schema::hasColumn('services', $c));
                if (!empty($cols)) $table->dropColumn($cols);
            });
        }

        if (Schema::hasTable('organization_services')) {
            Schema::table('organization_services', function (Blueprint $table) {
                $cols = array_filter(['base_duration_mins', 'base_sq_ft', 'increment_duration_mins', 'increment_sq_ft'], fn($c) => Schema::hasColumn('organization_services', $c));
                if (!empty($cols)) $table->dropColumn($cols);
            });
        }

        if (Schema::hasTable('product_options')) {
            Schema::table('product_options', function (Blueprint $table) {
                $cols = array_filter(['base_duration_mins', 'base_sq_ft', 'increment_duration_mins', 'increment_sq_ft'], fn($c) => Schema::hasColumn('product_options', $c));
                if (!empty($cols)) $table->dropColumn($cols);
            });
        }
    }
};
