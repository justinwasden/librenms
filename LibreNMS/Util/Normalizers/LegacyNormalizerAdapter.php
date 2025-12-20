<?php

namespace LibreNMS\Util\Normalizers;

use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Modules\Support\RestNormalizers;

/**
 * Adapter to bridge old static methods to new normalizer classes
 * This allows gradual migration without breaking existing code
 */
class LegacyNormalizerAdapter
{
    /**
     * Call a normalizer by method name, using new class if available,
     * falling back to old static method
     *
     * @param string $methodName Method name (e.g., 'normalizePureArraySensors')
     * @param Device $device
     * @param array $payload
     * @return array
     */
    public static function normalize(string $methodName, Device $device, array $payload): array
    {
        // Try new normalizer first
        $normalizer = NormalizerFactory::make($methodName);

        if ($normalizer) {
            Log::debug("Using new normalizer class for $methodName");
            return $normalizer->normalize($device, $payload);
        }

        // Fall back to old static method if it exists
        if (method_exists(RestNormalizers::class, $methodName)) {
            Log::debug("Falling back to legacy static method for $methodName");
            return RestNormalizers::$methodName($device, $payload);
        }

        Log::warning("No normalizer found for method: $methodName");
        return [];
    }

    /**
     * Batch normalize multiple payloads
     *
     * @param array $normalizers Array of ['method' => 'methodName', 'payload' => [...]]
     * @param Device $device
     * @return array Keyed by method name
     */
    public static function batchNormalize(array $normalizers, Device $device): array
    {
        $results = [];

        foreach ($normalizers as $item) {
            $methodName = $item['method'] ?? null;
            $payload = $item['payload'] ?? [];

            if (!$methodName) {
                continue;
            }

            $results[$methodName] = self::normalize($methodName, $device, $payload);
        }

        return $results;
    }
}
