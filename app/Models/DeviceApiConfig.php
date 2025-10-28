<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class DeviceApiConfig extends Model
{
    use HasFactory;

    protected $table = 'device_api_configs';

    protected $fillable = [
        'device_id',
        'schema_id',
        'base_url',
        'verify_ssl',
        'extra_headers',
        'values',
        'template_id',
    ];

    protected $casts = [
        'verify_ssl' => 'boolean',
        'extra_headers' => 'array',
        'values' => 'array',
    ];

    /**
     * Get the device this config belongs to
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    /**
     * Get the auth schema
     */
    public function schema(): BelongsTo
    {
        return $this->belongsTo(DeviceApiAuthSchema::class, 'schema_id');
    }

    /**
     * Get the template (if used)
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DeviceApiTemplate::class, 'template_id');
    }

    /**
     * Get a decrypted value from the config
     */
    public function getValue(string $key, $default = null)
    {
        $values = $this->values ?? [];

        if (!isset($values[$key])) {
            return $default;
        }

        $value = $values[$key];

        // Check if this field should be encrypted
        if ($this->schema) {
            // Use loaded relationship if available, otherwise query
            $fields = $this->schema->relationLoaded('fields')
                ? $this->schema->fields
                : $this->schema->fields()->get();

            $field = $fields->where('name', $key)->first();
            if ($field && $field->shouldEncrypt()) {
                try {
                    return Crypt::decryptString($value);
                } catch (\Exception $e) {
                    // Value might not be encrypted (migration scenario)
                    return $value;
                }
            }
        }

        return $value;
    }

    /**
     * Set an encrypted value in the config
     */
    public function setValue(string $key, $value): void
    {
        $values = $this->values ?? [];

        // Check if this field should be encrypted
        if ($this->schema) {
            // Use loaded relationship if available, otherwise query
            $fields = $this->schema->relationLoaded('fields')
                ? $this->schema->fields
                : $this->schema->fields()->get();

            $field = $fields->where('name', $key)->first();
            if ($field && $field->shouldEncrypt() && !empty($value)) {
                $value = Crypt::encryptString($value);
            }
        }

        $values[$key] = $value;
        $this->values = $values;
    }

    /**
     * Set multiple values at once
     */
    public function setValues(array $keyValues): void
    {
        foreach ($keyValues as $key => $value) {
            $this->setValue($key, $value);
        }
    }
}
