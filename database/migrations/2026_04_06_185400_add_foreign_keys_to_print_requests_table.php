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
        Schema::table('print_requests', function (Blueprint $table) {
            $table->foreign('agent_id')->references('uuid')->on('agents')->onDelete('cascade');
            $table->foreign('property_id')->references('uuid')->on('properties')->onDelete('cascade');
            $table->foreign('tour_id')->references('uuid')->on('tours')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_requests', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['property_id']);
            $table->dropForeign(['tour_id']);
        });
    }
};
