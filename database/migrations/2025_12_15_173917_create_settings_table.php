<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();                     // auto-increment numeric ID
            $table->uuid('uuid')->unique();   // UUID for external reference
            $table->string('key')->unique();  // e.g., media_rules, feature_flags
            $table->json('value');            // JSON object for settings
            $table->uuid('updated_by')->nullable(); // admin who last modified
            $table->timestamps();

            // Optional foreign key to users table (admins)
            $table->foreign('updated_by')->references('uuid')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
