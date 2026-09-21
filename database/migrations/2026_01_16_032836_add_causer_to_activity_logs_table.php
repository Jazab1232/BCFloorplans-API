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
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('causer_type')->nullable()->after('model_id');
            $table->uuid('causer_uuid')->nullable()->after('causer_type');

            $table->index(['causer_type', 'causer_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['causer_type', 'causer_uuid']);
            $table->dropColumn(['causer_type', 'causer_uuid']);
        });
    }
};
