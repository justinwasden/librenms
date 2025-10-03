<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to create device_api_metrics table
     */
    public function up(): void
    {
        if (!Schema::hasTable('device_api_metrics')) {
            Schema::create('device_api_metrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id');
                $table->unsignedBigInteger('api_endpoint_id');
                $table->unsignedBigInteger('api_connection_id')->nullable();
                
                // Resource identification
                $table->string('resource_type', 50)->index(); // array, volume, interface, host, etc.
                $table->string('resource_id')->index(); // UUID or name of the resource
                $table->string('resource_name')->index(); // Human-readable name
                
                // Metric data
                $table->string('metric_name')->index(); // e.g., 'capacity', 'speed', 'data_reduction'
                $table->string('metric_type', 20)->default('gauge'); // gauge, counter, etc.
                $table->decimal('value', 20, 4)->nullable(); // Numeric values
                $table->text('string_value')->nullable(); // String/JSON values
                $table->longText('raw_response')->nullable(); // Full API response if needed
                
                // Timestamps
                $table->timestamp('collected_at')->index();
                $table->timestamps();
                
                // Foreign keys
                $table->foreign('device_id')
                    ->references('device_id')
                    ->on('devices')
                    ->onDelete('cascade');
                    
                $table->foreign('api_endpoint_id')
                    ->references('id')
                    ->on('rest_api_endpoints')
                    ->onDelete('cascade');
                    
                $table->foreign('api_connection_id')
                    ->references('id')
                    ->on('rest_api_connections')
                    ->onDelete('set null');
                
                // Composite indexes for common queries
                $table->index(['device_id', 'resource_type', 'resource_id']);
                $table->index(['device_id', 'api_endpoint_id', 'collected_at']);
                $table->index(['resource_type', 'metric_name', 'collected_at']);
            });
        }
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('device_api_metrics');
    }
};
