<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // General context
            $table->string('source')->nullable(); // e.g. 'order'
            $table->uuid('source_id')->nullable(); // UUID of related entity (order, payment, etc.)
            $table->string('type')->nullable(); // e.g. 'order_created', 'payment_processing'
            $table->text('description')->nullable();

            // Recipients & roles
            $table->uuid('agent_uuid')->nullable();
            $table->json('vendor_uuids')->nullable(); // ✅ store multiple vendors as JSON array
            $table->uuid('user_uuid')->nullable(); // admin or customer
            $table->string('role')->nullable(); // e.g. 'agent', 'vendor', 'admin'
            $table->string('created_by_name');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
