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
        // Auth schemas table - defines authentication types
        Schema::create('api_auth_schemas', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('fields')->nullable(); // Field definitions with type, label, encrypted, required
            $table->boolean('is_system')->default(false); // Built-in vs user-created
            $table->timestamps();
        });

        // Templates table - defines API configurations per vendor/OS
        Schema::create('api_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('os_types')->nullable(); // Array of OS types this template applies to
            $table->string('auth_type', 50)->nullable(); // References api_auth_schemas.key
            $table->string('base_url_pattern', 255)->nullable(); // e.g., https://{hostname}/api
            $table->json('capabilities')->nullable(); // Array of capabilities: sensors, ports, etc.
            $table->boolean('is_system')->default(false); // Built-in vs user-created
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('auth_type');
        });

        // Template endpoints table - defines API endpoints per template
        Schema::create('api_template_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->string('name', 255)->nullable(); // Human-readable name
            $table->string('path', 500); // API path e.g., /api/v1/sensors
            $table->string('method', 10)->default('GET'); // HTTP method
            $table->string('capability', 50)->nullable(); // sensors, ports, inventory, etc.
            $table->string('transform', 255)->nullable(); // Normalizer method name
            $table->json('headers')->nullable(); // Additional headers
            $table->json('query_params')->nullable(); // Query parameters
            $table->json('body')->nullable(); // Request body for POST/PUT
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('template_id')
                ->references('id')
                ->on('api_templates')
                ->onDelete('cascade');

            $table->index(['template_id', 'capability']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_template_endpoints');
        Schema::dropIfExists('api_templates');
        Schema::dropIfExists('api_auth_schemas');
    }
};
