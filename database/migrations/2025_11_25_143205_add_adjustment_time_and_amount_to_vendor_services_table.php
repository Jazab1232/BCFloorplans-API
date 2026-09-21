<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_services', function (Blueprint $table) {
            $table->integer('adjustment_time')->default(0)->after('time_needed');
            $table->decimal('amount', 10, 2)->default(0)->after('adjustment_time');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_services', function (Blueprint $table) {
            $table->dropColumn(['adjustment_time', 'amount']);
        });
    }
};
