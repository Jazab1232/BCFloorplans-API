<?php
/**
 * Migration to ensure Super Admin role exists and is assigned to todd@tojuco.com.
 * Created on: 2026-05-09
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Role;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ensure the Super Admin role exists
        $role = Role::firstOrCreate(['name' => 'Super Admin']);

        // 2. Find Todd and assign the role
        $todd = User::where('email', 'todd@tojuco.com')->first();

        if ($todd) {
            // syncWithoutDetaching ensures we don't duplicate or remove other roles
            $todd->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $role = Role::where('name', 'Super Admin')->first();
        if ($role) {
            $todd = User::where('email', 'todd@tojuco.com')->first();
            if ($todd) {
                $todd->roles()->detach($role->id);
            }
        }
    }
};
