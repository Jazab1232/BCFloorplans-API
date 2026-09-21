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
        Schema::table('vendor_portfolio_images', function (Blueprint $schema) {
            $schema->boolean('is_processing')->default(false)->after('image_type');
            $schema->json('variants')->nullable()->after('is_processing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_portfolio_images', function (Blueprint $schema) {
            $schema->dropColumn(['is_processing', 'variants']);
        });
    }
};
