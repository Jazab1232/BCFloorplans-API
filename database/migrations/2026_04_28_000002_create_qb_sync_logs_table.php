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
        Schema::create('qb_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity_type'); // invoice, bill, customer, vendor, payment
            $table->unsignedBigInteger('entity_id');
            $table->string('qb_entity_id')->nullable();
            $table->string('qb_doc_number')->nullable();
            $table->string('action'); // create, update, void, delete, payment
            $table->string('status')->default('pending'); // pending, success, failed, skipped
            $table->uuid('request_id')->nullable()->index(); // For idempotency
            $table->text('error_message')->nullable();
            $table->string('error_code')->nullable();
            $table->integer('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qb_sync_logs');
    }
};
