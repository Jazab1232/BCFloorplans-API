<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // 1. Add fields to vendor_portfolio_images table
        if (Schema::hasTable('vendor_portfolio_images')) {
            Schema::table('vendor_portfolio_images', function (Blueprint $table) {
                if (!Schema::hasColumn('vendor_portfolio_images', 'image_type')) {
                    $table->enum('image_type', ['uploaded', 'tour_reference', 'external'])
                          ->default('uploaded')
                          ->after('image_path');
                }
                
                if (!Schema::hasColumn('vendor_portfolio_images', 'original_path')) {
                    $table->string('original_path')->nullable()->after('image_type');
                }
                
                
            });
        }

        // 2. Add is_featured flag to tour_files table
        if (Schema::hasTable('tour_files')) {
            Schema::table('tour_files', function (Blueprint $table) {
                if (!Schema::hasColumn('tour_files', 'is_featured')) {
                    $table->boolean('is_featured')
                          ->default(false)
                          ->after('file_type');
                }
            });
            
            // 3. Set all existing records to is_featured = false
            DB::table('tour_files')->update(['is_featured' => false]);
        }
    }

    public function down()
    {
        // Rollback vendor_portfolio_images changes
        if (Schema::hasTable('vendor_portfolio_images')) {
            Schema::table('vendor_portfolio_images', function (Blueprint $table) {
                $columns = ['image_type', 'original_path', 'tour_id'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('vendor_portfolio_images', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        // Rollback tour_files changes
        if (Schema::hasTable('tour_files')) {
            Schema::table('tour_files', function (Blueprint $table) {
                if (Schema::hasColumn('tour_files', 'is_featured')) {
                    $table->dropColumn('is_featured');
                }
            });
        }
    }
};