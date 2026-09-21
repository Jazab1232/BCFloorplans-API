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
        // 1. Master Vendor Pay Defaults on services table
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                if (!Schema::hasColumn('services', 'vendor_pay_type')) {
                    $table->string('vendor_pay_type')->default('flat')->after('pst_enabled');
                }
                if (!Schema::hasColumn('services', 'vendor_price')) {
                    $table->decimal('vendor_price', 10, 2)->default(0.00)->after('vendor_pay_type');
                }
                if (!Schema::hasColumn('services', 'vendor_sq_ft_rate')) {
                    $table->decimal('vendor_sq_ft_rate', 10, 4)->nullable()->after('vendor_price');
                }
                if (!Schema::hasColumn('services', 'vendor_min_price')) {
                    $table->decimal('vendor_min_price', 10, 2)->nullable()->after('vendor_sq_ft_rate');
                }
                if (!Schema::hasColumn('services', 'vendor_unit_rate')) {
                    $table->decimal('vendor_unit_rate', 10, 2)->nullable()->after('vendor_min_price');
                }
                if (!Schema::hasColumn('services', 'vendor_hourly_rate')) {
                    $table->decimal('vendor_hourly_rate', 10, 2)->nullable()->after('vendor_unit_rate');
                }
            });
        }

        // 2. Master Vendor Pay Defaults on product_options table
        if (Schema::hasTable('product_options')) {
            Schema::table('product_options', function (Blueprint $table) {
                if (!Schema::hasColumn('product_options', 'vendor_pay_type')) {
                    $table->string('vendor_pay_type')->default('flat')->after('sort_order');
                }
                if (!Schema::hasColumn('product_options', 'vendor_price')) {
                    $table->decimal('vendor_price', 10, 2)->default(0.00)->after('vendor_pay_type');
                }
                if (!Schema::hasColumn('product_options', 'vendor_sq_ft_rate')) {
                    $table->decimal('vendor_sq_ft_rate', 10, 4)->nullable()->after('vendor_price');
                }
                if (!Schema::hasColumn('product_options', 'vendor_min_price')) {
                    $table->decimal('vendor_min_price', 10, 2)->nullable()->after('vendor_sq_ft_rate');
                }
                if (!Schema::hasColumn('product_options', 'vendor_unit_rate')) {
                    $table->decimal('vendor_unit_rate', 10, 2)->nullable()->after('vendor_min_price');
                }
                if (!Schema::hasColumn('product_options', 'vendor_hourly_rate')) {
                    $table->decimal('vendor_hourly_rate', 10, 2)->nullable()->after('vendor_unit_rate');
                }
            });
        }

        // 3. Vendor Service Pay Rates on vendor_services table
        if (Schema::hasTable('vendor_services')) {
            Schema::table('vendor_services', function (Blueprint $table) {
                if (!Schema::hasColumn('vendor_services', 'pay_type')) {
                    $table->string('pay_type')->default('flat')->after('status');
                }
                if (!Schema::hasColumn('vendor_services', 'vendor_price')) {
                    $table->decimal('vendor_price', 10, 2)->default(0.00)->after('pay_type');
                }
                if (!Schema::hasColumn('vendor_services', 'sq_ft_rate')) {
                    $table->decimal('sq_ft_rate', 10, 4)->nullable()->after('vendor_price');
                }
                if (!Schema::hasColumn('vendor_services', 'min_price')) {
                    $table->decimal('min_price', 10, 2)->nullable()->after('sq_ft_rate');
                }
                if (!Schema::hasColumn('vendor_services', 'unit_rate')) {
                    $table->decimal('unit_rate', 10, 2)->nullable()->after('min_price');
                }
                if (!Schema::hasColumn('vendor_services', 'hourly_rate')) {
                    $table->decimal('hourly_rate', 10, 2)->nullable()->after('unit_rate');
                }
            });
        }

        // 4. Vendor Service Option Pay Rates on vendor_service_options table
        if (Schema::hasTable('vendor_service_options')) {
            Schema::table('vendor_service_options', function (Blueprint $table) {
                if (!Schema::hasColumn('vendor_service_options', 'pay_type')) {
                    $table->string('pay_type')->default('flat')->after('vendor_price');
                }
                if (!Schema::hasColumn('vendor_service_options', 'sq_ft_rate')) {
                    $table->decimal('sq_ft_rate', 10, 4)->nullable()->after('pay_type');
                }
                if (!Schema::hasColumn('vendor_service_options', 'min_price')) {
                    $table->decimal('min_price', 10, 2)->nullable()->after('sq_ft_rate');
                }
                if (!Schema::hasColumn('vendor_service_options', 'unit_rate')) {
                    $table->decimal('unit_rate', 10, 2)->nullable()->after('min_price');
                }
                if (!Schema::hasColumn('vendor_service_options', 'hourly_rate')) {
                    $table->decimal('hourly_rate', 10, 2)->nullable()->after('unit_rate');
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
                $cols = array_filter(['vendor_pay_type', 'vendor_price', 'vendor_sq_ft_rate', 'vendor_min_price', 'vendor_unit_rate', 'vendor_hourly_rate'], fn($col) => Schema::hasColumn('services', $col));
                if (!empty($cols)) $table->dropColumn($cols);
            });
        }

        if (Schema::hasTable('product_options')) {
            Schema::table('product_options', function (Blueprint $table) {
                $cols = array_filter(['vendor_pay_type', 'vendor_price', 'vendor_sq_ft_rate', 'vendor_min_price', 'vendor_unit_rate', 'vendor_hourly_rate'], fn($col) => Schema::hasColumn('product_options', $col));
                if (!empty($cols)) $table->dropColumn($cols);
            });
        }

        if (Schema::hasTable('vendor_services')) {
            Schema::table('vendor_services', function (Blueprint $table) {
                $cols = array_filter(['pay_type', 'vendor_price', 'sq_ft_rate', 'min_price', 'unit_rate', 'hourly_rate'], fn($col) => Schema::hasColumn('vendor_services', $col));
                if (!empty($cols)) $table->dropColumn($cols);
            });
        }

        if (Schema::hasTable('vendor_service_options')) {
            Schema::table('vendor_service_options', function (Blueprint $table) {
                $cols = array_filter(['pay_type', 'sq_ft_rate', 'min_price', 'unit_rate', 'hourly_rate'], fn($col) => Schema::hasColumn('vendor_service_options', $col));
                if (!empty($cols)) $table->dropColumn($cols);
            });
        }
    }
};
