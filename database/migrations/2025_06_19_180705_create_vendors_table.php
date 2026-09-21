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
        // 1. Main Vendors Table (Core Profile)
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('secondary_email')->nullable();
            $table->string('password');
            $table->boolean('notification_email')->default(false);
            $table->string('email_type')->nullable();
            $table->string('primary_phone');
            $table->string('secondary_phone')->nullable();
            $table->boolean('name_on_booking')->default(false);
            $table->boolean('review_files')->default(false);
            $table->boolean('sync_google_calendar')->default(false);
            $table->boolean('sync_google')->default(false);
            $table->enum('sync_email', ['primary', 'secondary', 'both'])->default('primary');
            $table->string('avatar')->nullable();
            $table->boolean('status')->default(false);
            $table->timestamps();
        });

        // 2. Vendor Companies Table (Branding/Company Info)
        Schema::create('vendor_companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('company_website')->nullable();
            $table->string('company_logo')->nullable();
            $table->string('company_banner')->nullable();
            $table->timestamps();
        });

        // 3. Vendor Addresses Table (Multiple Address Types)
        Schema::create('vendor_addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->enum('type', ['company', 'start_location', 'billing']);
            $table->text('address_line_1');
            $table->text('address_line_2')->nullable();
            $table->string('city');
            $table->string('province');
            $table->string('country')->default('Canada');
            $table->timestamps();
        });

        // 4. Vendor Work Hours Table (Schedule Configuration)
        Schema::create('vendor_work_hours', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->json('work_days'); // Store as JSON array e.g. ['mon','tue']
            $table->string('repeat_weekly');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->unsignedInteger('commute_minutes')->default(30);
            $table->string('timezone');
            $table->timestamps();
        });

        // 5. Vendor Rates & Areas Table (Service Configuration)
        Schema::create('vendor_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->decimal('payment_per_km', 8, 2)->default(0.68);
            $table->boolean('enable_service_area')->default(true);
            $table->boolean('force_service_area')->default(true);
            $table->timestamps();
        });

        // 6. Vendor Services Table (Pivot - Existing)
        Schema::create('vendor_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->decimal('hourly_rate', 8, 2)->default(0.00);
            $table->unsignedInteger('time_needed')->default(30);
            $table->boolean('status')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('vendor_companies');
        Schema::dropIfExists('vendor_addresses');
        Schema::dropIfExists('vendor_work_hours');
        Schema::dropIfExists('vendor_settings');
        Schema::dropIfExists('vendor_services');
    }
};
