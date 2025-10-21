<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // REST API Credentials table
        if (!Schema::hasTable('rest_api_credentials')) {
            Schema::create('rest_api_credentials', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->unsignedBigInteger('authentication_type_id');
                $table->text('notes')->nullable();
                $table->timestamps();
                
                $table->index('authentication_type_id');
            });
        }

        // REST API Credential Parameters table
        if (!Schema::hasTable('rest_api_credential_params')) {
            Schema::create('rest_api_credential_params', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('credential_id');
                $table->string('key');
                $table->text('value')->nullable();
                $table->timestamps();
                
                $table->foreign('credential_id')->references('id')->on('rest_api_credentials')->onDelete('cascade');
                $table->index(['credential_id', 'key']);
            });
        }

        // REST API Authentication Types table
        if (!Schema::hasTable('rest_api_authentication_types')) {
            Schema::create('rest_api_authentication_types', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->text('description')->nullable();
                $table->json('required_params')->nullable();
                $table->timestamps();
            });
        }

        // REST API Connections table
        if (!Schema::hasTable('rest_api_connections')) {
            Schema::create('rest_api_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('device_id');
                $table->string('name');
                $table->string('base_url');
                $table->unsignedBigInteger('credential_id')->nullable();
                $table->boolean('enabled')->default(true);
                $table->boolean('disable_ssl_verify')->default(false);
                $table->timestamps();
                
                $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
                $table->foreign('credential_id')->references('id')->on('rest_api_credentials')->onDelete('set null');
                $table->index(['device_id', 'enabled']);
            });
        }

        // REST API Endpoints table
        if (!Schema::hasTable('rest_api_endpoints')) {
            Schema::create('rest_api_endpoints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('connection_id');
                $table->string('name');
                $table->string('path');
                $table->string('method')->default('GET');
                $table->string('resource_type')->nullable();
                $table->json('metric_map')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                
                $table->foreign('connection_id')->references('id')->on('rest_api_connections')->onDelete('cascade');
                $table->index(['connection_id', 'enabled']);
            });
        }

        // REST API Metrics table (fallback storage)
        if (!Schema::hasTable('rest_api_metrics')) {
            Schema::create('rest_api_metrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('device_id');
                $table->string('endpoint_name')->nullable();
                $table->string('metric_key');
                $table->text('metric_value')->nullable();
                $table->string('resource_type')->nullable();
                $table->timestamp('last_updated')->nullable();
                $table->timestamps();
                
                $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
                $table->index(['device_id', 'metric_key']);
                $table->index(['device_id', 'resource_type']);
            });
        }

        // REST API Metric Field Mappings table
        if (!Schema::hasTable('rest_api_metric_field_mappings')) {
            Schema::create('rest_api_metric_field_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('device_id')->nullable(); // null = global mapping
                $table->string('api_field_name');
                $table->string('librenms_table');
                $table->string('librenms_field');
                $table->string('unit')->nullable();
                $table->string('transform')->nullable();
                $table->decimal('confidence_score', 5, 2)->default(0);
                $table->boolean('enabled')->default(true);
                $table->boolean('user_created')->default(false);
                $table->unsignedInteger('last_matched_device_id')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                
                $table->index(['api_field_name', 'librenms_table'], 'api_librenms_idx');
                $table->index(['device_id', 'enabled'], 'device_enabled_idx');
                $table->index('confidence_score', 'confidence_idx');
            });
        }

        // REST API Templates table
        if (!Schema::hasTable('rest_api_templates')) {
            Schema::create('rest_api_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('vendor')->nullable();
                $table->string('model')->nullable();
                $table->json('endpoints')->nullable();
                $table->json('mappings')->nullable();
                $table->boolean('is_global')->default(false);
                $table->timestamps();
                
                $table->index(['vendor', 'model']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('rest_api_metric_field_mappings');
        Schema::dropIfExists('rest_api_metrics');
        Schema::dropIfExists('rest_api_endpoints');
        Schema::dropIfExists('rest_api_connections');
        Schema::dropIfExists('rest_api_credential_params');
        Schema::dropIfExists('rest_api_credentials');
        Schema::dropIfExists('rest_api_authentication_types');
        Schema::dropIfExists('rest_api_templates');
    }
};
