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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('type', ['quantity', 'code']);
            $table->string('name')->nullable(); // Only for quantity type
            $table->string('code_key')->nullable(); // Only for code type
            $table->integer('quantity')->nullable(); // For quantity-based
            $table->decimal('percentage', 5, 2); // e.g. 12.00
            $table->date('expiry_date')->nullable(); // For both types
            $table->text('description')->nullable(); // For code-based and general description
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
