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
        if (Schema::hasTable('tour_files') && !Schema::hasColumn('tour_files', 'subtype')) {
            Schema::table('tour_files', function (Blueprint $table) {
                $table->string('subtype')->nullable()->default(null)->after('type')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tour_files') && Schema::hasColumn('tour_files', 'subtype')) {
            Schema::table('tour_files', function (Blueprint $table) {
                $table->dropIndex(['subtype']);
                $table->dropColumn('subtype');
            });
        }
    }
};
