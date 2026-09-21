<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_audio_files', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('agent_id')
                ->constrained('agents')
                ->cascadeOnDelete()
                ->index();

            $table->string('name');                     // Display name
            $table->string('file_path');                // storage/app path (S3 later)
            $table->string('mime_type', 100);           // audio/mpeg, audio/wav
            $table->unsignedBigInteger('size');         // bytes
            $table->unsignedInteger('duration')->nullable(); // seconds

            $table->boolean('is_active')->default(true);

            $table->uuid('uploaded_by')->nullable();    // admin/agent uuid

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_audio_files');
    }
};
