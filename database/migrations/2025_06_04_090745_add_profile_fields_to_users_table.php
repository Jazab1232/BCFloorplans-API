<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->nullable();
            $table->renameColumn('name', 'first_name');
            $table->string('last_name')->nullable();
            $table->string('secondary_email')->nullable();
            $table->string('primary_phone')->nullable();
            $table->string('secondary_phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->string('avatar')->nullable();
            $table->string('company_logo')->nullable();
            $table->string('company_banner')->nullable();
        });

        // Step 2: update existing rows with UUIDs
        \App\Models\User::whereNull('uuid')->get()->each(function ($user) {
            $user->uuid = Str::uuid();
            $user->save();
        });

        // Step 3: make UUID column NOT NULL
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('first_name', 'name');
            $table->dropColumn([
                'uuid', 'last_name', 'secondary_email',
                'primary_phone', 'secondary_phone', 'company_name', 'website',
                'address', 'city', 'province', 'country',
                'avatar', 'company_logo', 'company_banner'
            ]);
        });
    }
};
