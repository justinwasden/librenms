<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // API Authentication Schemas
        Schema::create('api_auth_schemas', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('fields')->nullable(); // Array of field definitions
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // API Templates
        Schema::create('api_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('os_types')->nullable(); // Array of OS types this template applies to
            $table->string('auth_type', 100); // References api_auth_schemas.key
            $table->string('base_url_pattern', 500)->nullable();
            $table->json('capabilities')->nullable(); // Array of capabilities
            $table->boolean('is_system')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('auth_type');
        });

        // API Template Endpoints
        Schema::create('api_template_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('api_templates')->onDelete('cascade');
            $table->string('capability', 100);
            $table->string('method', 10)->default('GET');
            $table->string('path', 500);
            $table->string('transform', 500)->nullable(); // Normalizer class/method
            $table->string('for_each', 100)->nullable(); // For iterative endpoints
            $table->json('body')->nullable(); // Request body template
            $table->json('headers')->nullable(); // Additional headers
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'capability']);
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_template_endpoints');
        Schema::dropIfExists('api_templates');
        Schema::dropIfExists('api_auth_schemas');
    }
};
