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
        Schema::create('vendor_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_invoice_id')->constrained('vendor_invoices')->onDelete('cascade');
            $table->unsignedBigInteger('order_service_id')->nullable(); // Set null if service is deleted
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->string('type')->default('service'); // service, travel, adjustment
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_invoice_lines');
    }
};
