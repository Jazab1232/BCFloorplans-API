<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_xx_xx_create_vendor_portfolio_images_table.php
        public function up()
        {
            // If you want to simplify, use this migration
            Schema::create('vendor_portfolio_images', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('vendor_id')->constrained()->onDelete('cascade');
                $table->string('image_path'); // Just the filename
                $table->timestamps();
                
                $table->index('vendor_id');
            });
        }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_portfolio_images');
    }
};
