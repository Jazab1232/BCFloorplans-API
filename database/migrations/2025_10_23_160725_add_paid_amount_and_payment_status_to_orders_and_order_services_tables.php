<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'paid_amount' to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('paid_amount', 10, 2)
                  ->default(0)
                  ->after('amount');
        });

        // Add 'payment_status' to order_services table
        Schema::table('order_services', function (Blueprint $table) {
            $table->enum('payment_status', ['PAID', 'UNPAID'])
                  ->default('UNPAID')
                  ->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });

        Schema::table('order_services', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });
    }
};
