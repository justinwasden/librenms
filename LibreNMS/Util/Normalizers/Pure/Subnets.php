<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Subnets Normalizer
 *
 * Capability: unknown
 * Vendor: pure
 */
class Subnets extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$networks = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $networks;
        }

        foreach ($payload['items'] as $subnet) {
            $prefix = $subnet['prefix'] ?? $subnet['subnet'] ?? null;

            if ($prefix) {
                $networks[] = [
                    'ipv4_network' => $prefix,
                    'context_name' => $subnet['name'] ?? null,
                ];
            }
        }

        return $networks;
    }
}
