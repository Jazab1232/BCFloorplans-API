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
        // For PostgreSQL, we need to modify the check constraints if they are used for enums
        // or just re-define the column if using Laravel's base implementation.
        // Since the error message mentioned "agent_payments_payment_type_check", it's a check constraint.
        
        if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE agent_payments DROP CONSTRAINT IF EXISTS agent_payments_status_check");
            DB::statement("ALTER TABLE agent_payments ADD CONSTRAINT agent_payments_status_check CHECK (status::text IN ('pending', 'processing', 'succeeded', 'failed', 'refunded'))");

            DB::statement("ALTER TABLE agent_payments DROP CONSTRAINT IF EXISTS agent_payments_payment_type_check");
            DB::statement("ALTER TABLE agent_payments ADD CONSTRAINT agent_payments_payment_type_check CHECK (payment_type::text IN ('full', 'partial', 'refund'))");
        } else {
            // For others (MySQL), we can just use change()
            Schema::table('agent_payments', function (Blueprint $table) {
                $table->string('status')->change();
                $table->string('payment_type')->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("ALTER TABLE agent_payments DROP CONSTRAINT IF EXISTS agent_payments_status_check");
            DB::statement("ALTER TABLE agent_payments ADD CONSTRAINT agent_payments_status_check CHECK (status::text IN ('pending', 'processing', 'succeeded', 'failed'))");

            DB::statement("ALTER TABLE agent_payments DROP CONSTRAINT IF EXISTS agent_payments_payment_type_check");
            DB::statement("ALTER TABLE agent_payments ADD CONSTRAINT agent_payments_payment_type_check CHECK (payment_type::text IN ('full', 'partial'))");
        }
    }
};
