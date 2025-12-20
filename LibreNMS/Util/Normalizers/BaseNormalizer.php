<?php

namespace LibreNMS\Util\Normalizers;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Normalizer;

/**
 * Base class for all normalizers
 * Provides common functionality and validation
 */
abstract class BaseNormalizer implements Normalizer
{
    protected string $capability;
    protected string $vendor;

    /**
     * Normalize with error handling and logging
     *
     * @param Device $device
     * @param array $payload
     * @return array
     */
    public function normalize(Device $device, array $payload): array
    {
        try {
            // Validate payload
            if (empty($payload)) {
                Log::debug("Empty payload for {$this->vendor} {$this->capability} normalizer on device {$device->device_id}");
                return [];
            }

            // Call vendor-specific normalization
            $result = $this->doNormalize($device, $payload);

            // Validate result
            if (!is_array($result)) {
                Log::warning("Normalizer " . static::class . " did not return array for device {$device->device_id}");
                return [];
            }

            return $result;

        } catch (\Throwable $e) {
            Log::error("Normalizer " . static::class . " failed for device {$device->device_id}: {$e->getMessage()}", [
                'exception' => $e,
                'device_id' => $device->device_id,
                'vendor' => $this->vendor,
                'capability' => $this->capability,
            ]);
            return [];
        }
    }

    /**
     * Vendor-specific normalization logic
     * Must be implemented by child classes
     *
     * @param Device $device
     * @param array $payload
     * @return array
     */
    abstract protected function doNormalize(Device $device, array $payload): array;

    /**
     * Get the capability this normalizer produces
     *
     * @return string
     */
    public function getCapability(): string
    {
        return $this->capability;
    }

    /**
     * Get the vendor this normalizer supports
     *
     * @return string
     */
    public function getVendor(): string
    {
        return $this->vendor;
    }

    /**
     * Helper: Convert bytes to TB
     *
     * @param int|float $bytes
     * @param int $precision
     * @return float
     */
    protected function bytesToTB($bytes, int $precision = 2): float
    {
        return round($bytes / 1099511627776, $precision);
    }

    /**
     * Helper: Convert bytes to GB
     *
     * @param int|float $bytes
     * @param int $precision
     * @return float
     */
    protected function bytesToGB($bytes, int $precision = 2): float
    {
        return round($bytes / 1073741824, $precision);
    }

    /**
     * Helper: Safely get nested array value
     *
     * @param array $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get(array $array, string $key, $default = null)
    {
        return $array[$key] ?? $default;
    }

    /**
     * Generate stable numeric index from string name
     * Uses CRC32 to ensure same name always gets same index
     * Constrained to fit in MySQL INT(11) column (max 2,147,483,647)
     *
     * @param string $name
     * @return int
     */
    protected function stableIndexFromName(string $name): int
    {
        return abs(crc32($name)) % 2147483647;
    }
}
