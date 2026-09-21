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
            $table->boolean('tax_enabled')->default(false)->after('vendor_id');
            $table->decimal('tax_rate', 8, 2)->default(0)->after('tax_enabled');
            $table->string('tax_number')->nullable()->after('tax_rate'); // GST/HST number
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_settings', function (Blueprint $table) {
            $table->dropColumn(['tax_enabled', 'tax_rate', 'tax_number']);
        });
    }
};
