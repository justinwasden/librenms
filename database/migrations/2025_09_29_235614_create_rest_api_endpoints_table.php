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
        Schema::create('rest_api_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('rest_api_connections')->onDelete('cascade');
            $table->string('name');
            $table->string('path');
            $table->string('method', 10)->default('GET');
            $table->json('query_params')->nullable();
            $table->json('headers')->nullable();
            $table->json('body')->nullable();
            $table->json('metric_map')->nullable();
            $table->timestamp('last_polled')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rest_api_endpoints');
    }
};