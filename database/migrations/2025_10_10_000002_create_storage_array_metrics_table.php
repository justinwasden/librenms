<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Create new simple metrics table
     */
    public function up(): void
    {
        Schema::create('storage_array_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id');
            $table->enum('metric_type', [
                'space_accounting',    // Pure Storage space breakdown (data_reduction, thin_provisioning, snapshots)
                'data_reduction',      // Data reduction ratios
                'host_connectivity',   // Host connection details
                'replication',         // Pod/replication metrics
                'performance_detail'   // Detailed performance breakdowns
            ]);
            $table->string('metric_name', 100); // e.g., 'total_data_reduction', 'host_connections', 'pod_replication_status'
            $table->json('metric_value');       // Flexible JSON storage for complex metrics
            $table->timestamp('last_updated')->useCurrent()->useCurrentOnUpdate();
            
            // Foreign key
            $table->foreign('device_id')
                  ->references('device_id')
                  ->on('devices')
                  ->onDelete('cascade');
            
            // Indexes
            $table->unique(['device_id', 'metric_type', 'metric_name'], 'idx_unique_device_metric');
            $table->index(['device_id', 'metric_type'], 'idx_device_type');
            $table->index('last_updated', 'idx_last_updated');
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_array_metrics');
    }
};
