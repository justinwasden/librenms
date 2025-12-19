<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cluster_id')->index();
            $table->timestamp('timestamp')->index();

            // Capacity
            $table->double('cpu_total_mhz')->nullable();
            $table->double('cpu_effective_mhz')->nullable();
            $table->double('cpu_usage_pct')->nullable();
            $table->double('memory_total_mb')->nullable();
            $table->double('memory_effective_mb')->nullable();
            $table->double('memory_usage_pct')->nullable();
            $table->unsignedBigInteger('storage_total_bytes')->nullable();
            $table->unsignedBigInteger('storage_effective_bytes')->nullable();
            $table->unsignedBigInteger('storage_used_bytes')->nullable();
            $table->double('storage_usage_pct')->nullable();
            $table->double('network_total_bw_mbps')->nullable();
            $table->double('network_usage_mbps')->nullable();
            $table->double('network_usage_pct')->nullable();

            // Performance
            $table->double('cpu_ready_time_ms')->nullable();
            $table->double('mem_balloon_mb')->nullable();
            $table->double('storage_iops_read')->nullable();
            $table->double('storage_iops_write')->nullable();
            $table->double('storage_bw_read_mbps')->nullable();
            $table->double('storage_bw_write_mbps')->nullable();
            $table->double('storage_latency_read_us')->nullable();
            $table->double('storage_latency_write_us')->nullable();
            $table->unsignedBigInteger('network_errors')->nullable();
            $table->unsignedBigInteger('network_drops')->nullable();
            $table->double('session_response_time_ms')->nullable();

            // Telemetry
            $table->double('event_rate_per_min')->nullable();
            $table->double('error_rate_per_min')->nullable();

            $table->timestamps();

            $table->foreign('cluster_id')->references('id')->on('clusters')->onDelete('cascade');
            $table->index(['cluster_id', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_metrics');
    }
};
