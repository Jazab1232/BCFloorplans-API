<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status::text IN ('PAID', 'UNPAID', 'PARTIAL', 'REFUNDED'))");
        } else {
            Schema::table('orders', function (Blueprint $table) {
                // Change to string first to remove enum constraints if necessary, or just use change() if using doctrine/dbal
                $table->string('payment_status')->default('UNPAID')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status::text IN ('PAID', 'UNPAID'))");
        } else {
            Schema::table('orders', function (Blueprint $table) {
                $table->enum('payment_status', ['PAID', 'UNPAID'])->default('UNPAID')->change();
            });
        }
    }
};
