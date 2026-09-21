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
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['vendor_id', 'est_time', 'distance', 'km_price', 'order_status', 'vendor_address', 'vendor_location']);

            // Update order_status enum
            $table->enum('order_status', [
                'Processing', 
                'Pending', 
                'Completed', 
                'On Hold',
                'In Progress',
                'Cancelled'
            ])->default('Processing')->after('payment_status');
            
            // Add new columns
            $table->json('co_agents')->nullable()->after('order_status');
            $table->json('notes')->nullable()->after('co_agents');
            $table->boolean('split_invoice')->default(false)->after('notes');
        });

        Schema::table('order_slots', function (Blueprint $table) {
            $table->string('start_time', 50)->nullable()->after('travel');
            $table->string('end_time', 50)->nullable()->after('start_time');
            $table->string('est_time')->nullable()->after('end_time');
            $table->string('distance')->nullable()->after('est_time');
            $table->string('km_price')->nullable()->after('distance');
            $table->string('address')->nullable()->after('km_price');
            $table->string('location')->nullable()->after('address');
        });

        Schema::create('order_totals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_service_id')->nullable()->constrained('order_services')->cascadeOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->enum('discount_type', ['quantity', 'code', 'manual'])->nullable();
            $table->decimal('discount_value', 10, 2)->nullable()->comment('The value used to calculate discount (percentage or fixed amount)');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->index(['order_id', 'order_service_id', 'discount_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->cascadeOnDelete();
            $table->dropColumn(['co_agents', 'notes', 'split_invoice', 'order_status']);
            $table->string('est_time')->nullable();
            $table->string('distance')->nullable();
            $table->string('km_price')->nullable();
            $table->enum('order_status', [
                'Processing', 
                'Pending', 
                'Completed', 
                'On Hold'
            ])->default('Processing')->after('payment_status');
        });

        Schema::table('order_slots', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time', 'est_time', 'distance', 'km_price', 'address', 'location']);
        });

        Schema::dropIfExists('order_totals');
    }
};
