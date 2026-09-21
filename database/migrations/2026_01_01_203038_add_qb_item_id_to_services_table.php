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
        Schema::table('services', function (Blueprint $table) {
            $table->string('quickbooks_item_id')->nullable()->after('price');
            $table->timestamp('quickbooks_synced_at')->nullable()->after('quickbooks_item_id');
            
            // Optional: Add index for faster lookups
            $table->index('quickbooks_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['quickbooks_item_id']);
            $table->dropColumn(['quickbooks_item_id', 'quickbooks_synced_at']);
        });
    }
};