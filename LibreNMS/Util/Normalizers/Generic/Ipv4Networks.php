<?php

namespace LibreNMS\Util\Normalizers\Generic;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Generic - Ipv4Networks Normalizer
 *
 * Capability: ports
 * Vendor: generic
 */
class Ipv4Networks extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'generic';

    protected function doNormalize(Device $device, array $payload): array
    {
$networks = [];
        $items = $payload['items'] ?? $payload['networks'] ?? $payload['routes'] ?? $payload;

        foreach ($items as $net) {
            $network = $net['network'] ?? $net['subnet'] ?? $net['cidr'] ?? null;
            if (!$network) {
                continue;
            }

            $networks[] = [
                'ipv4_network' => $network,
                'context_name' => $net['context'] ?? $net['vrf'] ?? null,
            ];
        }

        return $networks;
    }
}
