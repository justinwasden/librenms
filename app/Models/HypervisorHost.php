<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HypervisorHost extends Model
{
    protected $table = 'hypervisor_hosts';

    protected $fillable = [
        'device_id',
        'host_device_id',
        'host_type',
        'host_id',
        'host_name',
        'cluster_id',
        'role',
        'status',
        'version',
        'cpu_cores',
        'cpu_threads',
        'memory_total',
        'ip_address',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'cpu_cores' => 'integer',
        'cpu_threads' => 'integer',
        'memory_total' => 'integer',
    ];

    /**
     * Get the device that manages this host (e.g., vCenter)
     */
    public function managerDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Get the device object for this host (if monitored separately)
     */
    public function hostDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'host_device_id', 'device_id');
    }

    /**
     * Get the cluster this host belongs to
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(HypervisorCluster::class, 'cluster_id', 'cluster_id')
            ->where('device_id', $this->device_id);
    }

    /**
     * Get formatted host type label
     */
    public function getTypeLabel(): string
    {
        return match($this->host_type) {
            'esxi' => 'VMware ESXi',
            'proxmox-node' => 'Proxmox Node',
            'hyperv' => 'Hyper-V',
            'kvm' => 'KVM Host',
            default => ucfirst($this->host_type),
        };
    }

    /**
     * Get formatted status label with color
     */
    public function getStatusLabel(): array
    {
        return match(strtolower($this->status ?? 'unknown')) {
            'connected', 'online', 'running' => ['label' => 'Connected', 'class' => 'success'],
            'disconnected', 'offline' => ['label' => 'Disconnected', 'class' => 'danger'],
            'maintenance' => ['label' => 'Maintenance', 'class' => 'warning'],
            'standby' => ['label' => 'Standby', 'class' => 'info'],
            default => ['label' => 'Unknown', 'class' => 'secondary'],
        };
    }

    /**
     * Get formatted role label
     */
    public function getRoleLabel(): string
    {
        return match(strtolower($this->role ?? 'node')) {
            'master', 'primary' => 'Master',
            'node', 'member' => 'Node',
            'standalone' => 'Standalone',
            'replica', 'secondary' => 'Secondary',
            default => ucfirst($this->role ?? 'Node'),
        };
    }

    /**
     * Get formatted memory size
     */
    public function getMemoryFormatted(): string
    {
        if (!$this->memory_total) {
            return 'N/A';
        }

        $gb = $this->memory_total / 1073741824; // Convert bytes to GB
        return number_format($gb, 2) . ' GB';
    }
}
