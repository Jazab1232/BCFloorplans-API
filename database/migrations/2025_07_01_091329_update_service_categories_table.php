<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Change ENUM to VARCHAR
        Schema::table('service_categories', function (Blueprint $table) {
            $table->string('type', 50)->default('area')->change();
        });
    }

    public function down()
    {
        // Revert to original ENUM if needed
        Schema::table('service_categories', function (Blueprint $table) {
            $table->enum('type', ['area', 'fixed', 'quantity'])->default('area')->change();
        });
    }
};
