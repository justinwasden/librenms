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
        Schema::create('rest_api_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('authentication_type_id')->constrained('rest_api_authentication_types');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign key checks temporarily
        \DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        try {
            Schema::dropIfExists('rest_api_credentials');
        } finally {
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
};