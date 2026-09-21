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
        Schema::create('email_templates', function (Blueprint $row) {
            $row->id();
            $row->uuid('uuid')->unique();
            $row->string('title');
            $row->longText('content');
            $row->json('tags')->nullable();
            $row->string('type')->nullable(); // e.g., 'notification', 'reminder', 'system'
            $row->integer('sort_order')->default(0);
            $row->boolean('is_active')->default(true);
            $row->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
