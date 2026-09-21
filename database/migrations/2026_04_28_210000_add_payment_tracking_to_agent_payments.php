<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_payments', function (Blueprint $table) {
            // Who actually paid (for split invoice tracking)
            $table->unsignedBigInteger('paid_by_agent_id')->nullable()->after('agent_id');
            $table->foreign('paid_by_agent_id')->references('id')->on('agents')->nullOnDelete();

            // Payment mode: on_behalf = co-agent's money, self = payer's money
            $table->string('payment_mode', 20)->nullable()->after('payment_type');

            // Reference to the invoice being paid
            // (invoice_id may already exist from a previous migration, so skip if exists)

            $table->index(['paid_by_agent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_payments', function (Blueprint $table) {
            $table->dropForeign(['paid_by_agent_id']);
            $table->dropColumn(['paid_by_agent_id', 'payment_mode']);
        });
    }
};
