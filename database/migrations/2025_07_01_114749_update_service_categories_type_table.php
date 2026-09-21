<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the enum check constraint (PostgreSQL adds this automatically)
        DB::statement('ALTER TABLE service_categories DROP CONSTRAINT IF EXISTS service_categories_type_check');

        // Remove default first (important)
        DB::statement('ALTER TABLE service_categories ALTER COLUMN type DROP DEFAULT');

        // Convert type to JSON using explicit cast
        DB::statement('ALTER TABLE service_categories ALTER COLUMN type TYPE json USING to_json(type::text)');

        // Optional: Make nullable
        DB::statement('ALTER TABLE service_categories ALTER COLUMN type DROP NOT NULL');
    }

    public function down(): void
    {
        // Convert JSON back to string
        DB::statement('ALTER TABLE service_categories ALTER COLUMN type TYPE varchar(50) USING type::text');

        // Optional: Reinstate default and constraint if needed
        DB::statement("ALTER TABLE service_categories ALTER COLUMN type SET DEFAULT 'area'");
        DB::statement("ALTER TABLE service_categories ADD CONSTRAINT service_categories_type_check CHECK (type IN ('area', 'fixed', 'quantity'))");
    }
};
