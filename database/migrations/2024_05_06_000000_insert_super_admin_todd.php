<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::updateOrCreate(
            ['email' => 'todd@tojuco.com'],
            [
                'first_name' => 'Todd',
                'last_name' => 'Long',
                'password' => Hash::make('BCFloor486!'),
                'organization_id' => null,
                'uuid' => (string) Str::uuid(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::where('email', 'todd@tojuco.com')->delete();
    }
};
