<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enable notification emails for all existing vendors who had the old default (false).
     * Also set email_type to 'primary' where it was left null.
     */
    public function up(): void
    {
        // Update vendors where notification_email is still the old default (false/0)
        DB::table('vendors')
            ->where('notification_email', false)
            ->update([
                'notification_email' => true,
                'updated_at' => now(),
            ]);

        // Set email_type to 'primary' where it was left null
        DB::table('vendors')
            ->whereNull('email_type')
            ->update([
                'email_type' => 'primary',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No safe rollback — we cannot distinguish vendors that were intentionally
        // set to false from those that inherited the old default.
    }
};
