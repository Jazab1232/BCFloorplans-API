<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        /**
         * GLOBAL TOUR SETTINGS TABLE
         */
        Schema::create('global_tour_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('area');
            $table->string('type'); // photography, video, drone, etc.
            $table->decimal('charge', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->boolean('is_percentage')->default(false);

            $table->boolean('status')->default(true);

            $table->timestamps();
        });

       
    }

    public function down(): void
    {

        Schema::dropIfExists('global_tour_settings');
    }
};
