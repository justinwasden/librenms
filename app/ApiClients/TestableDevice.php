<?php

namespace App\ApiClients;

use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * A wrapper around Device for testing API connections without database writes.
 * This class duck-types the Device interface needed by API clients.
 */
class TestableDevice
{
    private Device $originalDevice;
    private Collection $testAttribs;

    // Expose common device properties
    public int $device_id;
    public string $hostname;
    public ?string $os;
    public ?string $sysObjectID;
    public ?string $ip;
    public ?string $hardware;
    public ?string $version;
    public ?string $serial;

    public function __construct(Device $device, $testAttribs)
    {
        $this->originalDevice = $device;
        $this->testAttribs = $testAttribs instanceof Collection ? $testAttribs : collect($testAttribs);

        // Copy essential properties
        $this->device_id = $device->device_id ?? 0;
        $this->hostname = $device->hostname ?? '';
        $this->os = $device->os;
        $this->sysObjectID = $device->sysObjectID;
        $this->ip = $device->ip;
        $this->hardware = $device->hardware;
        $this->version = $device->version;
        $this->serial = $device->serial;
    }

    /**
     * Get attribute - checks test attributes first, then falls back to original device
     */
    public function getAttrib($name, $default = null)
    {
        // First check test attributes
        $attrib = $this->testAttribs->first(function ($item) use ($name) {
            return $item->attrib_type === $name;
        });

        if ($attrib) {
            return $attrib->attrib_value;
        }

        // Fall back to original device's stored attributes
        return $this->originalDevice->getAttrib($name, $default);
    }

    /**
     * Set attribute - no-op for testing (doesn't write to database)
     */
    public function setAttrib($name, $value)
    {
        // Add to test attribs collection instead of database
        $existing = $this->testAttribs->first(function ($item) use ($name) {
            return $item->attrib_type === $name;
        });

        if ($existing) {
            $existing->attrib_value = $value;
        } else {
            $this->testAttribs->push((object) [
                'attrib_type' => $name,
                'attrib_value' => $value,
            ]);
        }

        return true;
    }

    /**
     * Forget attribute - no-op for testing (doesn't write to database)
     */
    public function forgetAttrib($name)
    {
        return true;
    }

    /**
     * Magic method to forward property access to original device
     */
    public function __get($name)
    {
        return $this->originalDevice->$name ?? null;
    }

    /**
     * Magic method to check if property exists
     */
    public function __isset($name)
    {
        return isset($this->originalDevice->$name);
    }

    /**
     * Get the original device (for cases where the real device is needed)
     */
    public function getOriginalDevice(): Device
    {
        return $this->originalDevice;
    }

    /**
     * Convert to array (for compatibility with code expecting arrays)
     */
    public function toArray(): array
    {
        return $this->originalDevice->toArray();
    }
}
