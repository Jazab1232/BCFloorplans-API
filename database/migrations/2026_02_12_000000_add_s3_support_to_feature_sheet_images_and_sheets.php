<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_sheet_images', function (Blueprint $table) {
            $table->json('variants')->nullable()->after('url');
            $table->boolean('is_processing')->default(false)->after('variants');
            $table->integer('order')->nullable()->after('is_processing');
        });

        Schema::table('feature_sheets', function (Blueprint $table) {
            $table->boolean('is_processing')->default(false)->after('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::table('feature_sheet_images', function (Blueprint $table) {
            $table->dropColumn(['variants', 'is_processing', 'order']);
        });

        Schema::table('feature_sheets', function (Blueprint $table) {
            $table->dropColumn('is_processing');
        });
    }
};
