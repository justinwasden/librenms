<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClusterMetric extends Model
{
    protected $table = 'cluster_metrics';

    protected $fillable = [
        'cluster_id',
        'timestamp',
        'cpu_total_mhz',
        'cpu_effective_mhz',
        'cpu_usage_pct',
        'memory_total_mb',
        'memory_effective_mb',
        'memory_usage_pct',
        'storage_total_bytes',
        'storage_effective_bytes',
        'storage_used_bytes',
        'storage_usage_pct',
        'network_total_bw_mbps',
        'network_usage_mbps',
        'network_usage_pct',
        'cpu_ready_time_ms',
        'mem_balloon_mb',
        'storage_iops_read',
        'storage_iops_write',
        'storage_bw_read_mbps',
        'storage_bw_write_mbps',
        'storage_latency_read_us',
        'storage_latency_write_us',
        'network_errors',
        'network_drops',
        'session_response_time_ms',
        'event_rate_per_min',
        'error_rate_per_min',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class, 'cluster_id');
    }
}
