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
        Schema::create('matterport_renewals', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('tour_id')->constrained('tours')->onDelete('cascade');
            $table->foreignId('tour_link_id')->nullable()->constrained('tour_links')->onDelete('set null');
            $table->foreignId('agent_id')->nullable()->constrained('agents')->onDelete('set null');
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->onDelete('cascade');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->date('previous_expiry_date')->nullable();
            $table->date('new_expiry_date');
            $table->integer('duration_months')->default(6);
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('payment_status')->default('PAID');
            $table->string('payment_method')->nullable();
            $table->string('renewed_by_type')->nullable(); // admin, agent, system
            $table->unsignedBigInteger('renewed_by_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matterport_renewals');
    }
};
