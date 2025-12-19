<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClusterNode extends Model
{
    protected $table = 'cluster_nodes';

    protected $fillable = [
        'cluster_id',
        'node_name',
        'role',
        'effective',
        'cpu_total_mhz',
        'memory_total_mb',
        'storage_total_bytes',
        'network_bw_mbps',
        'state',
        'last_seen_at',
    ];

    protected $casts = [
        'effective' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class, 'cluster_id');
    }
}
