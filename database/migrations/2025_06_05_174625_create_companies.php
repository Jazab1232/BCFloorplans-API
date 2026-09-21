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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('website');
            $table->string('email');
            $table->string('primary_phone');
            $table->string('secondary_phone')->nullable();
            $table->string('street');
            $table->string('city');
            $table->string('province');
            $table->string('country')->default('Canada');
            $table->string('billing_street_1');
            $table->string('billing_street_2')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->boolean('review_files')->default(true);
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('work_days'); // Comma-separated: 'Mon,Tue,Wed,Thu,Fri'
            $table->boolean('repeat_weekly')->default(true);
            $table->string('timezone');
            $table->unsignedInteger('commute_minutes')->default(30);
            $table->boolean('enable_breaks')->default(false);
            $table->boolean('sync_google')->default(false);
            $table->enum('sync_email', ['primary', 'secondary'])->default('primary');
            $table->decimal('payment_per_km', 8, 2)->default(0.68);
            $table->text('order_form_url')->nullable();
            $table->text('iframe_code')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('account_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->boolean('status')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'account_closed', 'closed_at', 'status'
            ]);
        });
    }
};
