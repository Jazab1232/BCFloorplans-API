<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_sheets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('order_id')->nullable();
            $table->enum('type', ['template', 'pdf'])->default('template');
            $table->string('template_key')->nullable();
            $table->json('content')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_url')->nullable();
            $table->enum('uploaded_by', ['agent', 'admin', 'vendor'])->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('feature_sheet_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('feature_sheet_id');
            $table->uuid('uuid')->unique();
            $table->string('slot');
            $table->string('storage_path');
            $table->string('url');
            $table->string('mime')->nullable();
            $table->integer('size')->nullable();
            $table->json('meta')->nullable();
            $table->enum('uploaded_by', ['agent', 'admin', 'vendor'])->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->foreign('feature_sheet_id')->references('id')->on('feature_sheets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_sheet_images');
        Schema::dropIfExists('feature_sheets');
    }
};

