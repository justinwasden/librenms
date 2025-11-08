<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorToStateIndex extends Model
{
    protected $table = 'sensors_to_state_indexes';
    protected $primaryKey = 'sensors_to_state_translations_id';
    public $timestamps = false;
    protected $fillable = ['sensor_id', 'state_index_id'];

    protected $casts = [
        'sensor_id' => 'int',
        'state_index_id' => 'int',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Sensor, $this>
     */
    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class, 'sensor_id', 'sensor_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\StateIndex, $this>
     */
    public function stateIndex(): BelongsTo
    {
        return $this->belongsTo(StateIndex::class, 'state_index_id', 'state_index_id');
    }
}
