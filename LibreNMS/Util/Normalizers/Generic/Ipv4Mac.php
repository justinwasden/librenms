<?php

namespace LibreNMS\Util\Normalizers\Generic;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Generic - Ipv4Mac Normalizer
 *
 * Capability: ipv4
 * Vendor: generic
 */
class Ipv4Mac extends BaseNormalizer
{
    protected string $capability = 'ipv4';
    protected string $vendor = 'generic';

    protected function doNormalize(Device $device, array $payload): array
    {
$mappings = [];
        $items = $payload['items'] ?? $payload['arp'] ?? $payload['arp_table'] ?? $payload;

        foreach ($items as $entry) {
            $ifIdentifier = null;
            if (isset($entry['ifIndex'])) {
                $ifIdentifier = ['ifIndex' => $entry['ifIndex']];
            } elseif (isset($entry['ifName'])) {
                $ifIdentifier = ['ifName' => $entry['ifName']];
            } elseif (isset($entry['interface'])) {
                $ifIdentifier = ['ifName' => $entry['interface']];
            }

            $mac = $entry['mac'] ?? $entry['mac_address'] ?? $entry['hwaddr'] ?? null;
            $ip = $entry['ip'] ?? $entry['address'] ?? $entry['ipv4_address'] ?? null;

            if (!$ifIdentifier || !$mac || !$ip) {
                continue;
            }

            $mappings[] = array_merge($ifIdentifier, [
                'mac_address'  => $mac,
                'ipv4_address' => $ip,
                'context_name' => $entry['context'] ?? $entry['vrf'] ?? '',
            ]);
        }

        return $mappings;
    }
}
