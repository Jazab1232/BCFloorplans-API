<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_download_jobs', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->uuid('uuid')->unique();
            $blueprint->foreignId('user_id')->constrained()->cascadeOnDelete();
            $blueprint->string('status')->default('pending'); // pending, processing, completed, failed
            $blueprint->integer('processed_count')->default(0);
            $blueprint->integer('file_count')->default(0);
            $blueprint->string('zip_path')->nullable();
            $blueprint->text('error_message')->nullable();
            $blueprint->json('options')->nullable(); // For size preference, filters, etc.
            $blueprint->timestamp('expires_at')->nullable();
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_download_jobs');
    }
};
