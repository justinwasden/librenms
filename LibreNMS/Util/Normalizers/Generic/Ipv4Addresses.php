<?php

namespace LibreNMS\Util\Normalizers\Generic;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Generic - Ipv4Addresses Normalizer
 *
 * Capability: ipv4
 * Vendor: generic
 */
class Ipv4Addresses extends BaseNormalizer
{
    protected string $capability = 'ipv4';
    protected string $vendor = 'generic';

    protected function doNormalize(Device $device, array $payload): array
    {
$addresses = [];
        $items = $payload['items'] ?? $payload['addresses'] ?? $payload;

        foreach ($items as $addr) {
            $ifIdentifier = null;
            if (isset($addr['ifIndex'])) {
                $ifIdentifier = ['ifIndex' => $addr['ifIndex']];
            } elseif (isset($addr['ifName'])) {
                $ifIdentifier = ['ifName' => $addr['ifName']];
            } elseif (isset($addr['interface'])) {
                $ifIdentifier = ['ifName' => $addr['interface']];
            }

            if (!$ifIdentifier || !isset($addr['address']) && !isset($addr['ip'])) {
                continue;
            }

            $prefixlen = $addr['prefixlen'] ?? $addr['prefix_length'] ?? 24;
            if (isset($addr['netmask']) && !is_numeric($addr['netmask'])) {
                $prefixlen = $this->netmaskToCidr($addr['netmask']);
            }

            $addresses[] = array_merge($ifIdentifier, [
                'ipv4_address'   => $addr['address'] ?? $addr['ip'],
                'ipv4_prefixlen' => $prefixlen,
                'context_name'   => $addr['context'] ?? $addr['vrf'] ?? '',
            ]);
        }

        return $addresses;
    }
}
