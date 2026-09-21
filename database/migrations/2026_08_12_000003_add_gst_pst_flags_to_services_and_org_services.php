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
        // 1. Add GST/PST flags to services table
        if (Schema::hasTable('services')) {
            Schema::table('services', function (Blueprint $table) {
                if (!Schema::hasColumn('services', 'gst_enabled')) {
                    $table->boolean('gst_enabled')->default(true)->after('status');
                }
                if (!Schema::hasColumn('services', 'pst_enabled')) {
                    $table->boolean('pst_enabled')->default(false)->after('gst_enabled');
                }
            });
        }

        // 2. Add GST/PST overrides to organization_services table
        if (Schema::hasTable('organization_services')) {
            Schema::table('organization_services', function (Blueprint $table) {
                if (!Schema::hasColumn('organization_services', 'gst_enabled')) {
                    $table->boolean('gst_enabled')->nullable()->after('is_enabled');
                }
                if (!Schema::hasColumn('organization_services', 'pst_enabled')) {
                    $table->boolean('pst_enabled')->nullable()->after('gst_enabled');
                }
            });
        }

        // 3. Add tax breakdown fields to invoice_items table
        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                if (!Schema::hasColumn('invoice_items', 'tax_amount')) {
                    $table->decimal('tax_amount', 10, 2)->default(0.00)->after('amount');
                }
                if (!Schema::hasColumn('invoice_items', 'gst_amount')) {
                    $table->decimal('gst_amount', 10, 2)->default(0.00)->after('tax_amount');
                }
                if (!Schema::hasColumn('invoice_items', 'pst_amount')) {
                    $table->decimal('pst_amount', 10, 2)->default(0.00)->after('gst_amount');
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
                if (Schema::hasColumn('services', 'gst_enabled')) {
                    $table->dropColumn('gst_enabled');
                }
                if (Schema::hasColumn('services', 'pst_enabled')) {
                    $table->dropColumn('pst_enabled');
                }
            });
        }

        if (Schema::hasTable('organization_services')) {
            Schema::table('organization_services', function (Blueprint $table) {
                if (Schema::hasColumn('organization_services', 'gst_enabled')) {
                    $table->dropColumn('gst_enabled');
                }
                if (Schema::hasColumn('organization_services', 'pst_enabled')) {
                    $table->dropColumn('pst_enabled');
                }
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $cols = array_filter(['tax_amount', 'gst_amount', 'pst_amount'], fn($col) => Schema::hasColumn('invoice_items', $col));
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
