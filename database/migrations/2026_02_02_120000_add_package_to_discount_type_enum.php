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
        // Check what enum types exist in the database
        $existingEnums = DB::select("SELECT typname FROM pg_type WHERE typtype = 'e' AND typname LIKE '%discount%'");
        
        if (empty($existingEnums)) {
            // No enum exists, let's create it from scratch
            DB::statement("ALTER TABLE order_totals ALTER COLUMN discount_type TYPE VARCHAR(20)");
            DB::statement("ALTER TABLE order_totals ADD CONSTRAINT discount_type_check CHECK (discount_type IN ('package', 'quantity', 'code', 'manual'))");
        } else {
            // Enum exists, try to modify it
            $enumName = $existingEnums[0]->typname;
            
            // Create new enum with package included
            DB::statement("CREATE TYPE {$enumName}_new AS ENUM ('package', 'quantity', 'code', 'manual')");
            
            // Update column to use new enum
            DB::statement("ALTER TABLE order_totals ALTER COLUMN discount_type TYPE {$enumName}_new USING discount_type::text::{$enumName}_new");
            
            // Drop old enum and rename new one
            DB::statement("DROP TYPE {$enumName}");
            DB::statement("ALTER TYPE {$enumName}_new RENAME TO {$enumName}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversing enum changes is complex in PostgreSQL and can cause data loss
        // Not implemented for safety
    }
};