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
        if (Schema::hasTable('organization_services')) {
            Schema::table('organization_services', function (Blueprint $table) {
                if (!Schema::hasColumn('organization_services', 'vendor_pay_type')) {
                    $table->string('vendor_pay_type')->nullable()->after('pst_enabled');
                }
                if (!Schema::hasColumn('organization_services', 'vendor_price')) {
                    $table->decimal('vendor_price', 10, 2)->nullable()->after('vendor_pay_type');
                }
                if (!Schema::hasColumn('organization_services', 'vendor_sq_ft_rate')) {
                    $table->decimal('vendor_sq_ft_rate', 10, 4)->nullable()->after('vendor_price');
                }
                if (!Schema::hasColumn('organization_services', 'vendor_min_price')) {
                    $table->decimal('vendor_min_price', 10, 2)->nullable()->after('vendor_sq_ft_rate');
                }
                if (!Schema::hasColumn('organization_services', 'vendor_unit_rate')) {
                    $table->decimal('vendor_unit_rate', 10, 2)->nullable()->after('vendor_min_price');
                }
                if (!Schema::hasColumn('organization_services', 'vendor_hourly_rate')) {
                    $table->decimal('vendor_hourly_rate', 10, 2)->nullable()->after('vendor_unit_rate');
                }
                if (!Schema::hasColumn('organization_services', 'add_ons_override')) {
                    $table->json('add_ons_override')->nullable()->after('options_override');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('organization_services')) {
            Schema::table('organization_services', function (Blueprint $table) {
                $cols = array_filter([
                    'vendor_pay_type',
                    'vendor_price',
                    'vendor_sq_ft_rate',
                    'vendor_min_price',
                    'vendor_unit_rate',
                    'vendor_hourly_rate',
                    'add_ons_override'
                ], fn($c) => Schema::hasColumn('organization_services', $c));

                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
