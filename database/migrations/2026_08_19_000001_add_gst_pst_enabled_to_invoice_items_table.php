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
        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                if (!Schema::hasColumn('invoice_items', 'gst_enabled')) {
                    $table->boolean('gst_enabled')->default(true)->after('amount');
                }
                if (!Schema::hasColumn('invoice_items', 'pst_enabled')) {
                    $table->boolean('pst_enabled')->default(false)->after('gst_enabled');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $cols = array_filter(['gst_enabled', 'pst_enabled'], fn($col) => Schema::hasColumn('invoice_items', $col));
                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
