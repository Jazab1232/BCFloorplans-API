<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // relations
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('order_service_id')->nullable()->constrained('order_services')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            // payment details
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('usd');
            $table->string('payment_method')->nullable();
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed'])->default('pending');
            $table->enum('payment_type', ['full', 'partial'])->default('full');

            // Stripe ids
            $table->string('stripe_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->text('stripe_receipt_url')->nullable();

            // Timestamps for payment events
            $table->timestamp('session_created_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // integration metadata
            $table->jsonb('meta')->nullable();
            $table->string('quickbooks_txn_id')->nullable();

            $table->timestamps();

            // Indexes for better performance
            $table->index(['status', 'created_at']);
            $table->index(['agent_id', 'created_at']);
            $table->index(['stripe_payment_intent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_payments');
    }
};