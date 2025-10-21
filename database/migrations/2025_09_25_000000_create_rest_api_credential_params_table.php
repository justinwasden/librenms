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
        if (Schema::hasTable('rest_api_credential_params')) {
            return;
        }
        
        Schema::create('rest_api_credential_params', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained('rest_api_credentials')->onDelete('cascade');
            $table->string('key');
            $table->text('value');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rest_api_credential_params');
    }
};