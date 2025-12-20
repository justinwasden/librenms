<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Vlans Normalizer
 *
 * Capability: vlans
 * Vendor: velocloud
 */
class Vlans extends BaseNormalizer
{
    protected string $capability = 'vlans';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $vlans = [];

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $edge) {
            // Get edge configuration segments
            $segments = $edge['segments'] ?? [];
            if (!is_array($segments)) {
                continue;
            }

            foreach ($segments as $segment) {
                $vlanId = $segment['segmentId'] ?? null;
                $vlanName = $segment['name'] ?? "Segment-{$vlanId}";

                if ($vlanId !== null) {
                    $vlans[] = [
                        'vlan_vlan' => $vlanId,
                        'vlan_domain' => 1,
                        'vlan_name' => $vlanName,
                        'vlan_type' => 'ethernet',
                        'vlan_mtu' => null,
                    ];
                }
            }
        }

        return $vlans;
    }
}
