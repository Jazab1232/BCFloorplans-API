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
        Schema::create('print_requests', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('feature_sheet_id');
            $table->uuid('agent_id');
            $table->uuid('property_id');
            $table->uuid('tour_id');
            $table->integer('copies');
            $table->boolean('with_bleed')->default(false);
            $table->text('additional_info')->nullable();
            $table->enum('status', ['Pending', 'Processing', 'Completed', 'Cancelled'])->default('Pending');
            $table->timestamps();

            $table->foreign('feature_sheet_id')->references('uuid')->on('feature_sheets')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_requests');
    }
};
