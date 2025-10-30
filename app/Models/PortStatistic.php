<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortStatistic extends PortRelatedModel
{
    protected $table = 'ports_statistics';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $casts = [
        'id' => 'int',
        'port_id' => 'int',
        'timestamp' => 'datetime',
        // Optionally cast counters if you read them via Eloquent
        // 'ifInOctets' => 'int',
        // 'ifOutOctets' => 'int',
        // 'ifInErrors' => 'int',
        // 'ifOutErrors' => 'int',
        // 'ifInUcastPkts' => 'int',
        // 'ifOutUcastPkts' => 'int',
        // ... add others as needed
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Port, $this>
     */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_id', 'port_id');
    }
}
