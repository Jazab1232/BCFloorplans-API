<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('booking_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_slot_id');
            $table->string('type'); // '24h', '2h'
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->foreign('order_slot_id')->references('id')->on('order_slots')->onDelete('cascade');
            $table->index(['order_slot_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_reminders');
    }
};
