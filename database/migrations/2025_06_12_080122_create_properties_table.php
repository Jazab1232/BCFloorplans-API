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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Property details
            $table->decimal('listing_price', 10, 2);
            $table->string('mls_number')->unique();
            $table->unsignedTinyInteger('bedrooms');
            $table->float('bathrooms');
            $table->unsignedInteger('square_footage');
            $table->string('lot_size');
            $table->year('year_constructed');
            $table->unsignedTinyInteger('parking_spots');
            $table->string('property_type');
            $table->string('property_status');
            $table->string('heading');
            $table->text('description');
            $table->string('suite')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('province');
            $table->string('postal_code');
            $table->string('country')->default('Canada');
            $table->boolean('status')->default(true);
            
            // Additional details
            $table->boolean('tour_activated')->default(false);
            $table->dateTime('publish_date')->nullable();
            $table->string('property_website')->nullable();
            $table->string('mls_property')->nullable();
            $table->string('occupancy')->default('Single Vacant');
            $table->string('media_creator_access')->nullable();
            $table->string('instructions')->nullable();
            $table->boolean('animals_on_property')->default(false);
            $table->json('co_agents')->nullable();
            $table->boolean('send_statistics_email')->default(false);
            $table->string('statistics_email_frequency')->nullable();
            $table->json('statistics_email_recipients')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
