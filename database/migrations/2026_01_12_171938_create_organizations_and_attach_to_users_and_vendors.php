<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | ORGANIZATIONS TABLE
        |----------------------------------------------------------------------
        */
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();                          // Fast internal joins
            $table->uuid('uuid')->unique();        // Public API identifier

            $table->string('name');
            $table->string('slug')->unique();

            // Contact info
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            // Address
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();

            // Optional owner (purely informational)
            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        /*
        |----------------------------------------------------------------------
        | USERS TABLE
        |----------------------------------------------------------------------
        | organization_id = NULL  → Platform Super Admin
        | organization_id != NULL → Organization User / Admin (role decides)
        */
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->nullOnDelete();
        });

        /*
        |----------------------------------------------------------------------
        | VENDORS TABLE
        |----------------------------------------------------------------------
        | Vendors always belong to an organization
        */
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::dropIfExists('organizations');
    }
};