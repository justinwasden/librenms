<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
      public function up(): void
    {
        Schema::create('rest_api_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('vendor')->nullable();
            // FIX: ADD THIS LINE:
            $table->text('description')->nullable();
            $table->text('template_data'); // JSON blob
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('rest_api_templates');
    }
};