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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->string('email')->unique();
            $table->string('email_cc')->nullable();
            $table->string('primary_phone');
            $table->string('secondary_phone')->nullable();
            $table->string('company_name');
            $table->string('website')->nullable();
            $table->string('license_number')->nullable();
            $table->json('certifications')->nullable();
            $table->text('headquarter_address')->nullable();
            $table->text('notes')->nullable();
            $table->json('co_agents')->nullable();
            $table->boolean('requires_payment')->default(false);
            $table->boolean('status')->default(false);
            $table->enum('payment_status', ['GOOD', 'ARREARS'])->default('GOOD');
            $table->string('avatar')->nullable();
            $table->string('company_logo')->nullable();
            $table->string('company_banner')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
