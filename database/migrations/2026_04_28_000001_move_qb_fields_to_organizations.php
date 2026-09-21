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
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('qb_realm_id')->nullable()->after('owner_user_id');
            $table->text('qb_access_token')->nullable()->after('qb_realm_id');
            $table->text('qb_refresh_token')->nullable()->after('qb_access_token');
            $table->timestamp('qb_access_expires_at')->nullable()->after('qb_refresh_token');
            $table->timestamp('qb_refresh_expires_at')->nullable()->after('qb_access_expires_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'qb_realm_id',
                'qb_access_token',
                'qb_refresh_token',
                'qb_access_expires_at',
                'qb_refresh_expires_at'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('qb_realm_id')->nullable();
            $table->text('qb_access_token')->nullable();
            $table->text('qb_refresh_token')->nullable();
            $table->timestamp('qb_access_expires_at')->nullable();
            $table->timestamp('qb_refresh_expires_at')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'qb_realm_id',
                'qb_access_token',
                'qb_refresh_token',
                'qb_access_expires_at',
                'qb_refresh_expires_at'
            ]);
        });
    }
};
