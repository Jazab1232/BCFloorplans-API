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
            DB::statement("ALTER TABLE order_services DROP CONSTRAINT IF EXISTS order_services_payment_status_check");
            DB::statement("ALTER TABLE order_services ADD CONSTRAINT order_services_payment_status_check CHECK (payment_status::text IN ('PAID', 'UNPAID', 'REFUNDED'))");
        } else {
            Schema::table('order_services', function (Blueprint $table) {
                $table->string('payment_status')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE order_services DROP CONSTRAINT IF EXISTS order_services_payment_status_check");
            DB::statement("ALTER TABLE order_services ADD CONSTRAINT order_services_payment_status_check CHECK (payment_status::text IN ('PAID', 'UNPAID'))");
        }
    }
};
