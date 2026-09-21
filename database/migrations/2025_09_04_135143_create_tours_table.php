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
        Schema::create('tours', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->json('slide_show')->nullable();
            $table->timestamps();
        });

        Schema::create('tour_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tour_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->string('name');
            $table->string('file_path');
            $table->string('group')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tour_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tour_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->unsignedBigInteger('service_id')->nullable();
            $table->text('link');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('tour_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tour_id')->constrained()->onDelete('cascade');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('file_name');
            $table->string('file_path');
            $table->decimal('x_axis', 10, 6)->default(0);
            $table->decimal('y_axis', 10, 6)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_snapshots');
        Schema::dropIfExists('tour_links');
        Schema::dropIfExists('tour_files');
        Schema::dropIfExists('tours');
    }
};
