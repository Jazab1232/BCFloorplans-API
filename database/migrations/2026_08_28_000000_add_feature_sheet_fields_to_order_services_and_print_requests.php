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
        Schema::table('order_services', function (Blueprint $table) {
            if (!Schema::hasColumn('order_services', 'feature_sheet_id')) {
                $table->unsignedBigInteger('feature_sheet_id')->nullable()->after('option_id');
                $table->foreign('feature_sheet_id')->references('id')->on('feature_sheets')->nullOnDelete();
            }
            if (!Schema::hasColumn('order_services', 'feature_sheet_uuid')) {
                $table->uuid('feature_sheet_uuid')->nullable()->after('feature_sheet_id');
            }
        });

        Schema::table('print_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('print_requests', 'option_id')) {
                $table->string('option_id')->nullable()->after('copies');
            }
            if (!Schema::hasColumn('print_requests', 'amount')) {
                $table->decimal('amount', 10, 2)->nullable()->after('option_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_services', function (Blueprint $table) {
            if (Schema::hasColumn('order_services', 'feature_sheet_id')) {
                $table->dropForeign(['feature_sheet_id']);
                $table->dropColumn('feature_sheet_id');
            }
            if (Schema::hasColumn('order_services', 'feature_sheet_uuid')) {
                $table->dropColumn('feature_sheet_uuid');
            }
        });

        Schema::table('print_requests', function (Blueprint $table) {
            if (Schema::hasColumn('print_requests', 'option_id')) {
                $table->dropColumn('option_id');
            }
            if (Schema::hasColumn('print_requests', 'amount')) {
                $table->dropColumn('amount');
            }
        });
    }
};
