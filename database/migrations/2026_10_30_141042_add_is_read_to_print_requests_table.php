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
        Schema::table('print_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('print_requests', 'is_read')) {
                $table->boolean('is_read')->default(false)->after('status');
            }
            if (!Schema::hasColumn('print_requests', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('is_read');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_requests', function (Blueprint $table) {
            if (Schema::hasColumn('print_requests', 'is_read')) {
                $table->dropColumn('is_read');
            }
            if (Schema::hasColumn('print_requests', 'read_at')) {
                $table->dropColumn('read_at');
            }
        });
    }
};
