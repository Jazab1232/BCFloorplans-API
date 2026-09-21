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
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('listing_price', 10, 2)->nullable()->change();
            $table->string('mls_number')->nullable()->change();
            $table->unsignedTinyInteger('bedrooms')->nullable()->change();
            $table->float('bathrooms')->nullable()->change();
            $table->unsignedInteger('square_footage')->nullable()->change();
            $table->string('lot_size')->nullable()->change();
            $table->year('year_constructed')->nullable()->change();
            $table->unsignedTinyInteger('parking_spots')->nullable()->change();
            $table->string('property_type')->nullable()->change();
            $table->string('property_status')->nullable()->change();
            $table->string('heading')->nullable()->change();
            $table->text('description')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('listing_price', 10, 2)->nullable(false)->change();
            $table->string('mls_number')->nullable(false)->unique()->change();
            $table->unsignedTinyInteger('bedrooms')->nullable(false)->change();
            $table->float('bathrooms')->nullable(false)->change();
            $table->unsignedInteger('square_footage')->nullable(false)->change();
            $table->string('lot_size')->nullable(false)->change();
            $table->year('year_constructed')->nullable(false)->change();
            $table->unsignedTinyInteger('parking_spots')->nullable(false)->change();
            $table->string('property_type')->nullable(false)->change();
            $table->string('property_status')->nullable(false)->change();
            $table->string('heading')->nullable(false)->change();
            $table->text('description')->nullable(false)->change();
        });
    }
};
