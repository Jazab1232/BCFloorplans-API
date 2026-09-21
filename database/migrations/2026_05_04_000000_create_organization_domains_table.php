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
        Schema::create('organization_domains', function (Blueprint $table) {
            $table->id();
            
            // Link back to the organization
            $table->foreignId('organization_id')
                  ->constrained('organizations')
                  ->cascadeOnDelete();
                  
            // The custom domain (e.g. agent.mypropertymedia.com)
            $table->string('domain')->unique();
            
            // The portal type: admin, agent, vendor
            $table->enum('portal_type', ['admin', 'agent', 'vendor']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_domains');
    }
};
