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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('property_address')->nullable();
            $table->string('property_location')->nullable();
            $table->string('vendor_address')->nullable();
            $table->string('vendor_location')->nullable();
            $table->string('est_time')->nullable();
            $table->string('distance')->nullable();
            $table->string('km_price')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->enum('order_status', ['Processing', 'Pending', 'Completed', 'On Hold'])->default('Processing');
            $table->enum('payment_status', ['PAID', 'UNPAID'])->default('UNPAID');
            $table->timestamps();
        });

        Schema::create('order_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('option_id')->nullable()->constrained('product_options')->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('custom')->nullable();
            $table->timestamps();
        });

        Schema::create('order_slots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->boolean('show_all_vendors')->default(true);
            $table->boolean('schedule_override')->default(true);
            $table->boolean('recommend_time')->default(true);
            $table->string('travel')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_slots');
        Schema::dropIfExists('order_services');
        Schema::dropIfExists('orders');
    }
};
