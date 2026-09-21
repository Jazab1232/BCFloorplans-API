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
        Schema::table('payment_methods', function (Blueprint $table) {
            // Add polymorphic columns
            $table->string('payable_type')->nullable()->after('user_id');
            $table->unsignedBigInteger('payable_id')->nullable()->after('payable_type');
            
            // Create index for polymorphic relationship
            $table->index(['payable_type', 'payable_id']);
        });
        
        // Convert existing user relationships to polymorphic format
        DB::statement("UPDATE payment_methods SET payable_type = 'App\\\\Models\\\\User', payable_id = user_id WHERE user_id IS NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Remove polymorphic columns and index
            $table->dropIndex(['payable_type', 'payable_id']);
            $table->dropColumn(['payable_type', 'payable_id']);
        });
    }
};
