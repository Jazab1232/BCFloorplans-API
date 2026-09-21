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
        Schema::create('tour_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained('tours')->onDelete('cascade');
            $table->date('date');
            $table->integer('views')->default(0);
            $table->timestamps();

            // Unique index for upserts
            $table->unique(['tour_id', 'date']);
        });

        Schema::create('tour_visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained('tours')->onDelete('cascade');
            $table->string('visitor_identifier')->index(); // UUID from frontend
            $table->timestamps();
        });

        Schema::create('tour_daily_referrers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained('tours')->onDelete('cascade');
            $table->date('date');
            $table->string('referrer_domain');
            $table->integer('count')->default(0);
            $table->timestamps();

            // Unique index for upserts
            $table->unique(['tour_id', 'date', 'referrer_domain']);
        });

        Schema::create('tour_daily_media_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tour_id')->constrained('tours')->onDelete('cascade');
            $table->date('date');
            $table->string('media_uuid')->index(); // FK logic handled in app if strict FK not possible, strict FK ideally to tour_files(uuid) if that's the key
            $table->integer('views')->default(0);
            $table->timestamps();

            // Unique index for upserts
            $table->unique(['tour_id', 'date', 'media_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tour_daily_media_stats');
        Schema::dropIfExists('tour_daily_referrers');
        Schema::dropIfExists('tour_visitors');
        Schema::dropIfExists('tour_daily_stats');
    }
};
