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
            if(!Schema::hasColumn('agents', 'agent_discount')) {
                $table->json('agent_discount')->nullable()->after('email');
            }
                Schema::table('agents', function (Blueprint $table) {
                    if (Schema::hasColumn('agents', 'status')) {
                $table->boolean('status')->default(true)->change();
                    }
            });
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('agent_discount');
        });

    }
};
