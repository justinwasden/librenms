<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use LibreNMS\Interfaces\Models\Keyable;

class Ipv4Mac extends PortRelatedModel implements Keyable
{
    protected $table = 'ipv4_mac';
    public $timestamps = false;
    protected $fillable = [
        'port_id',
        'device_id',
        'mac_address',
        'ipv4_address',
        'context_name',
    ];

    protected $casts = [
        'port_id' => 'int',
        'device_id' => 'int',
        'mac_address' => 'string',
        'ipv4_address' => 'string',
        'context_name' => 'string',
    ];

    // ---- Define Relationships ----

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Port, $this>
     */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_id');
    }

    /**
     * Query ports in NMS with a matching MAC address AND IPv4 address.
     * This can match multiple ports (e.g. sub-interfaces, VRFs).
     *
     * Usage: $ipv4Mac->queryRemotePortsMaybe()->get()
     */
    public function queryRemotePortsMaybe(): Builder
    {
        // Assumes ports.ifPhysAddress stores raw hex (e.g., 001122334455)
        return Port::query()
            ->join('ipv4_addresses', function ($j) {
                $j->on('ports.port_id', '=', 'ipv4_addresses.port_id');
            })
            ->where('ipv4_addresses.ipv4_address', '=', $this->ipv4_address)
            ->where('ports.ifPhysAddress', '=', $this->mac_address)
            ->when($this->context_name !== null, function (Builder $q) {
                $q->where('ipv4_addresses.context_name', '=', $this->context_name);
            })
            ->whereNotIn('ports.ifPhysAddress', ['000000000000', 'ffffffffffff']);
    }

    public function getCompositeKey(): string
    {
        $context = $this->context_name ?? '';
        return "{$this->device_id}-{$this->port_id}-{$this->ipv4_address}-{$context}";
    }
}