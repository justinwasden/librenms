<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceApiAuthSchemaField extends Model
{
    use HasFactory;

    protected $table = 'device_api_auth_schema_fields';

    protected $fillable = [
        'schema_id',
        'name',
        'label',
        'type',
        'required',
        'encrypted',
        'default',
        'placeholder',
        'options',
        'display_order',
    ];

    protected $casts = [
        'required' => 'boolean',
        'encrypted' => 'boolean',
        'options' => 'array',
    ];

    /**
     * Get the schema this field belongs to
     */
    public function schema(): BelongsTo
    {
        return $this->belongsTo(DeviceApiAuthSchema::class, 'schema_id');
    }

    /**
     * Check if this field should be encrypted when stored
     */
    public function shouldEncrypt(): bool
    {
        return $this->encrypted || $this->type === 'password';
    }
}
