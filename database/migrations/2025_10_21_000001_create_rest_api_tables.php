<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rest_api_templates')) {
            Schema::create('rest_api_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('vendor');
                $table->text('description')->nullable();
                $table->json('template_data');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('rest_api_endpoints')) {
            Schema::create('rest_api_endpoints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('template_id')->constrained('rest_api_templates')->onDelete('cascade');
                $table->string('name');
                $table->string('path');
                $table->string('http_method')->default('GET');
                $table->integer('poll_interval')->default(300);
                $table->string('resource_type');
                $table->json('template_response_mapping');
                $table->timestamps();

                $table->unique(['template_id', 'name']);
            });
        }

        if (!Schema::hasTable('rest_api_mappings')) {
            Schema::create('rest_api_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('endpoint_id')->constrained('rest_api_endpoints')->onDelete('cascade');
                $table->string('source_field');
                $table->string('target_field');
                $table->string('target_table');
                $table->string('data_type')->default('string');
                $table->boolean('is_identifier')->default(false);
                $table->boolean('is_required')->default(false);
                $table->text('transform_logic')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('rest_api_credentials')) {
            Schema::create('rest_api_credentials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('device_id')->constrained('devices')->onDelete('cascade');
                $table->string('auth_type');
                $table->string('username')->nullable();
                $table->text('password')->nullable();
                $table->text('auth_token')->nullable();
                $table->json('extra_data')->nullable();
                $table->timestamps();

                $table->unique('device_id');
            });
        }

        if (!Schema::hasTable('rest_api_device_templates')) {
            Schema::create('rest_api_device_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('device_id')->constrained('devices')->onDelete('cascade');
                $table->foreignId('template_id')->constrained('rest_api_templates')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['device_id', 'template_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rest_api_device_templates');
        Schema::dropIfExists('rest_api_credentials');
        Schema::dropIfExists('rest_api_mappings');
        Schema::dropIfExists('rest_api_endpoints');
        Schema::dropIfExists('rest_api_templates');
    }
};
