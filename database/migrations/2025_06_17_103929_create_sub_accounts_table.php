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
        Schema::create('sub_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->foreignId('agent_id')->constrained('agents')->onDelete('cascade');
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->string('primary_email')->unique();
            $table->string('secondary_email')->nullable();
            $table->string('password');
            $table->boolean('notification_email')->default(false);
            $table->string('email_type')->nullable();
            $table->string('primary_phone');
            $table->string('secondary_phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->default('Canada');
            $table->json('permissions')->nullable();
            $table->string('avatar')->nullable();
            $table->string('company_logo')->nullable();
            $table->string('company_banner')->nullable();
            $table->boolean('status')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notification_email')->default(false);
            $table->string('email_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_accounts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notification_email', 'email_type']);
        });
    }
};
