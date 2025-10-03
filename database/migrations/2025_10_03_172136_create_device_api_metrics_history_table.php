<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to create device_api_metrics_history table
     * This table stores historical metric data for trending
     */
    public function up(): void
    {
        if (!Schema::hasTable('device_api_metrics_history')) {
            Schema::create('device_api_metrics_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('device_id');
                $table->unsignedBigInteger('api_endpoint_id');
                $table->unsignedBigInteger('api_connection_id')->nullable();

                // Resource identification
                $table->string('resource_type', 50)->index();
                $table->string('resource_id')->index();
                $table->string('resource_name')->index();

                // Metric data
                $table->string('metric_name')->index();
                $table->string('metric_type', 20)->default('gauge');
                $table->decimal('value', 20, 4)->nullable();
                $table->text('string_value')->nullable();

                // Timestamps
                $table->timestamp('collected_at')->index();
                $table->timestamp('created_at')->useCurrent();

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

                // Composite indexes for time-series queries
                $table->index(['device_id', 'resource_id', 'metric_name', 'collected_at'], 'metrics_history_timeseries');
                $table->index(['resource_type', 'metric_name', 'collected_at'], 'metrics_history_type_time');
            });
        }
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('device_api_metrics_history');
    }
};
