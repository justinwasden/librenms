<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HypervisorCluster extends Model
{
    protected $table = 'hypervisor_clusters';

    protected $fillable = [
        'device_id',
        'cluster_type',
        'cluster_id',
        'cluster_name',
        'parent_id',
        'parent_name',
        'cluster_level',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get the device that manages this cluster
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Get all hosts in this cluster
     */
    public function hosts(): HasMany
    {
        return $this->hasMany(HypervisorHost::class, 'cluster_id', 'cluster_id')
            ->where('device_id', $this->device_id);
    }

    /**
     * Get formatted cluster type label
     */
    public function getTypeLabel(): string
    {
        return match($this->cluster_type) {
            'vmware' => 'VMware',
            'proxmox' => 'Proxmox VE',
            'hyperv' => 'Hyper-V',
            'kvm' => 'KVM',
            default => ucfirst($this->cluster_type),
        };
    }

    /**
     * Get formatted cluster level label
     */
    public function getLevelLabel(): string
    {
        return match($this->cluster_level) {
            'datacenter' => 'Datacenter',
            'cluster' => 'Cluster',
            'resource_pool' => 'Resource Pool',
            default => ucfirst($this->cluster_level),
        };
    }
}
