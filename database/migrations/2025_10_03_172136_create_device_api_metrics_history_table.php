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
                $table->unsignedBigInteger('api_endpoint_id')->nullable();
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

                // Composite indexes for time-series queries
                $table->index(['device_id', 'resource_id', 'metric_name', 'collected_at'], 'metrics_history_timeseries');
                $table->index(['resource_type', 'metric_name', 'collected_at'], 'metrics_history_type_time');
            });
        }

        // Add optional foreign keys if the referenced tables exist
        if (Schema::hasTable('device_api_metrics_history') && Schema::hasTable('rest_api_endpoints')) {
            Schema::table('device_api_metrics_history', function (Blueprint $table) {
                if (!Schema::hasColumn('device_api_metrics_history', 'api_endpoint_id') || 
                    !$this->hasForeignKey('device_api_metrics_history', 'api_endpoint_id')) {
                    try {
                        $table->foreign('api_endpoint_id')
                            ->references('id')
                            ->on('rest_api_endpoints')
                            ->onDelete('cascade');
                    } catch (\Exception $e) {
                        // Foreign key might already exist or tables don't match
                    }
                }
            });
        }

        if (Schema::hasTable('device_api_metrics_history') && Schema::hasTable('rest_api_connections')) {
            Schema::table('device_api_metrics_history', function (Blueprint $table) {
                if (!Schema::hasColumn('device_api_metrics_history', 'api_connection_id') || 
                    !$this->hasForeignKey('device_api_metrics_history', 'api_connection_id')) {
                    try {
                        $table->foreign('api_connection_id')
                            ->references('id')
                            ->on('rest_api_connections')
                            ->onDelete('set null');
                    } catch (\Exception $e) {
                        // Foreign key might already exist or tables don't match
                    }
                }
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

    /**
     * Helper to check if foreign key exists
     */
    private function hasForeignKey($table, $column): bool
    {
        $table_name = $table;
        if (!Schema::hasTable($table_name)) {
            return false;
        }

        $database = \DB::connection()->getDatabaseName();
        $result = \DB::select("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL", [$database, $table_name, $column]);

        return count($result) > 0;
    }
};
