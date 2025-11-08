<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LibreNMS\Interfaces\Models\Keyable;

class StorageArray extends Model implements Keyable
{
    protected $table = 'storage_arrays';

    protected $fillable = [
        'device_id',
        'vendor', 'model', 'serial', 'array_name', 'software_version',
        'total_bytes', 'used_bytes', 'free_bytes', 'used_pct', 'data_reduction_ratio',
        'controllers_count', 'volumes_count', 'hosts_count', 'replication_links_count',
        'alerts_open_count', 'last_polled_at',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    public function controllers(): HasMany
    {
        return $this->hasMany(StorageController::class, 'device_id', 'device_id');
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(StorageVolume::class, 'device_id', 'device_id');
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(StorageHost::class, 'device_id', 'device_id');
    }

    public function getCompositeKey()
    {
        return (string) $this->device_id;
    }

    // Helper to recompute used_pct/free from totals
    public function fillCapacity(?int $used, ?int $total, ?int $free = null): self
    {
        $this->used_bytes = (int) ($used ?? 0);
        $this->total_bytes = (int) ($total ?? 0);
        $this->free_bytes = (int) ($free ?? max(0, $this->total_bytes - $this->used_bytes));

        $this->used_pct = $this->total_bytes > 0
            ? round(($this->used_bytes / $this->total_bytes) * 100, 2)
            : 0;

        return $this;
    }
}
