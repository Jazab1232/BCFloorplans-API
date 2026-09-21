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
        Schema::table('services', function (Blueprint $table) {
            $table->string('status')->default(false);
            $table->string('background_color', 25)->nullable();
            $table->string('border_color', 25)->nullable();
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('service_id')->constrained();
            $table->string('title');
            $table->integer('quantity')->default(1);
            $table->string('sq_ft_range')->nullable();
            $table->decimal('sq_ft_rate', 8, 2)->nullable();
            $table->string('service_duration')->nullable();
            $table->decimal('amount', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['status', 'background_color', 'border_color']);
        });

        Schema::dropIfExists('product_options');
    }
};
