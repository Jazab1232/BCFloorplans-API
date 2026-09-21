<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        /**
         * 1. UPDATE vendor_services TABLE
         *    → Remove columns that belong to the new table
         */
        Schema::table('vendor_services', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_services', 'hourly_rate')) {
                $table->dropColumn('hourly_rate');
            }
            if (Schema::hasColumn('vendor_services', 'time_needed')) {
                $table->dropColumn('time_needed');
            }
            if (Schema::hasColumn('vendor_services', 'adjustment_time')) {
                $table->dropColumn('adjustment_time');
            }
            if (Schema::hasColumn('vendor_services', 'amount')) {
                $table->dropColumn('amount');
            }
        });

        /**
         * 2. CREATE vendor_service_options TABLE
         */
        Schema::create('vendor_service_options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('vendor_service_id')
                ->constrained('vendor_services')
                ->cascadeOnDelete();

            $table->foreignId('option_id')
                ->constrained('product_options')
                ->cascadeOnDelete();

            // Vendor custom attributes
            $table->decimal('vendor_price', 10, 2)->nullable();
            $table->integer('vendor_adjustment_time')->nullable(); // delivery time for this vendor option

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down()
    {
        /**
         * 1. DROP vendor_service_options TABLE
         */
        Schema::dropIfExists('vendor_service_options');

        /**
         * 2. REVERT vendor_services TABLE CHANGES
         */
        Schema::table('vendor_services', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->integer('time_needed')->nullable();
            $table->integer('adjustment_time')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
        });
    }
};
