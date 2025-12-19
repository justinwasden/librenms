<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cluster extends Model
{
    protected $table = 'clusters';

    protected $fillable = [
        'device_id',
        'cluster_name',
        'provider_type',
        'location',
        'environment',
        'business_service',
        'owner_team',
        'software_version',
        'api_version',
        'config_hash',
        'last_config_change',
        'state',
        'maintenance_mode',
    ];

    protected $casts = [
        'maintenance_mode' => 'boolean',
        'last_config_change' => 'datetime',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(ClusterNode::class, 'cluster_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ClusterMetric::class, 'cluster_id');
    }
}
