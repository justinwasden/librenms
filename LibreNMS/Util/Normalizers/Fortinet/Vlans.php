<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - Vlans Normalizer
 *
 * Capability: vlans
 * Vendor: fortigate
 */
class Vlans extends BaseNormalizer
{
    protected string $capability = 'vlans';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$vlans = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $vlans;
        }

        foreach ($results as $iface) {
            $type = $iface['type'] ?? '';
            $vlanid = $iface['vlanid'] ?? 0;
            $name = $iface['name'] ?? '';

            // Only process VLAN interfaces
            if ($type === 'vlan' && $vlanid > 0 && $name) {
                $vlans[] = [
                    'vlan_vlan' => $vlanid,
                    'vlan_domain' => 1,
                    'vlan_name' => $name,
                    'vlan_type' => 'ethernet',
                    'vlan_mtu' => $iface['mtu'] ?? null,
                ];
            }
        }

        return $vlans;
    }
}
