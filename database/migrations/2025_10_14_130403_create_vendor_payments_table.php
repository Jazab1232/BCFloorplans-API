<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
   
    Schema::create('vendor_payments', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('vendor_uuid');
    $table->string('order_service_uuid');
    $table->decimal('amount', 10, 2);
    $table->string('currency', 10)->default('usd');
    $table->string('stripe_transfer_id')->nullable();
    $table->string('stripe_balance_transaction_id')->nullable();
    $table->string('invoice_url')->nullable(); // 👈 for Stripe dashboard proof link
    $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
    $table->text('notes')->nullable();
    $table->json('metadata')->nullable(); // 👈 store raw Stripe response (for debugging/auditing)
    $table->timestamps();
});
}

public function down()
{
    Schema::dropIfExists('vendor_payments');
}

};
