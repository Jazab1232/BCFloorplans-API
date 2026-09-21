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
        Schema::table('vendor_settings', function (Blueprint $table) {
            $table->string('tax_country')->default('CA')->after('tax_number');
            $table->string('tax_type')->default('GST_HST')->after('tax_country');
            $table->string('tax_number_gst_hst')->nullable()->after('tax_type');
            $table->string('tax_number_pst')->nullable()->after('tax_number_gst_hst');
            $table->string('tax_number_qst')->nullable()->after('tax_number_pst');
            $table->string('tax_number_us')->nullable()->after('tax_number_qst');
            $table->boolean('tax_exempt')->default(false)->after('tax_number_us');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_settings', function (Blueprint $table) {
            $table->dropColumn([
                'tax_country',
                'tax_type',
                'tax_number_gst_hst',
                'tax_number_pst',
                'tax_number_qst',
                'tax_number_us',
                'tax_exempt',
            ]);
        });
    }
};
