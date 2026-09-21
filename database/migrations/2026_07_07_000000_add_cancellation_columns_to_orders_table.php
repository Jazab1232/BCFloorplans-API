<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('cancellation_fee', 10, 2)->nullable()->after('amount');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_fee');
            $table->string('cancelled_by_type')->nullable()->after('cancelled_at');
            $table->string('cancelled_by_uuid')->nullable()->after('cancelled_by_type');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'cancellation_fee',
                'cancelled_at',
                'cancelled_by_type',
                'cancelled_by_uuid',
                'cancellation_reason',
            ]);
        });
    }
};
