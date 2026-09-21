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
        Schema::table('agents', function (Blueprint $table) {
            if(!Schema::hasColumn('agents', 'quickbooks_customer_id')) {
                $table->string('quickbooks_customer_id')->nullable()->index();
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            if(Schema::hasColumn('agents', 'quickbooks_customer_id')) {
                $table->dropColumn('quickbooks_customer_id');
            }
        });
    }
};
