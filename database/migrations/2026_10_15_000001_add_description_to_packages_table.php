<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                if (!Schema::hasColumn('packages', 'description')) {
                    $table->text('description')->nullable()->after('name');
                }
                if (!Schema::hasColumn('packages', 'discount_type')) {
                    $table->string('discount_type', 20)->default('percentage')->after('discount');
                }
            });

            // Change discount from integer to numeric(10,2) to allow dollar values with decimals
            DB::statement("ALTER TABLE packages ALTER COLUMN discount TYPE numeric(10,2) USING discount::numeric(10,2);");
            DB::statement("ALTER TABLE packages ALTER COLUMN discount SET DEFAULT 0;");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('packages')) {
            Schema::table('packages', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('packages', 'description')) {
                    $columnsToDrop[] = 'description';
                }
                if (Schema::hasColumn('packages', 'discount_type')) {
                    $columnsToDrop[] = 'discount_type';
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });

            // Revert discount to integer
            DB::statement("ALTER TABLE packages ALTER COLUMN discount TYPE integer USING discount::integer;");
            DB::statement("ALTER TABLE packages ALTER COLUMN discount SET DEFAULT 0;");
        }
    }
};
