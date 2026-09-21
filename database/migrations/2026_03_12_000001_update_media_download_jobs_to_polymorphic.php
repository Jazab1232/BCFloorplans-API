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
        Schema::table('media_download_jobs', function (Blueprint $table) {
            // Drop old foreign key
            $table->dropForeign(['user_id']);
            
            // Make user_id nullable and add user_type for polymorphic relationship
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('user_type')->nullable()->after('user_id');
            
            // Add index for performance
            $table->index(['user_id', 'user_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_download_jobs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'user_type']);
            $table->dropColumn('user_type');
            
            // Restore foreign key (note: this might fail if there are non-user records)
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
