<?php

namespace LibreNMS\Util\Normalizers;

use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * Adapter to bridge old static method calls to new normalizer classes
 *
 * All normalizers are now in individual classes under LibreNMS\Util\Normalizers\{Vendor}\
 */
class LegacyNormalizerAdapter
{
    /**
     * Call a normalizer by method name
     *
     * @param string $methodName Method name (e.g., 'normalizePureArraySensors')
     * @param Device $device
     * @param array $payload
     * @return array
     */
    public static function normalize(string $methodName, Device $device, array $payload): array
    {
        $normalizer = NormalizerFactory::make($methodName);

        if ($normalizer) {
            return $normalizer->normalize($device, $payload);
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
