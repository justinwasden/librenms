<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - Ports Normalizer
 *
 * Capability: ports
 * Vendor: velocloud
 */
class Ports extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $ports = [];

        if (!is_array($data)) {
            return [];
        }

        // Handle two possible formats:
        // 1. Array of link metrics from getAggregateEdgeLinkMetrics (flat array)
        // 2. Nested structure with 'links' key
        $links = $data;
        if (isset($data['links']) && is_array($data['links'])) {
            $links = $data['links'];
        }

        // If data is not a numeric array, it might be a single link or wrong format
        if (empty($links) || !isset($links[0])) {
            return [];
        }

        $ifIndex = 1;
        foreach ($links as $link) {
            // getAggregateEdgeLinkMetrics uses 'name' and 'linkId'
            // Other endpoints might use 'link' or 'interface'
            $linkName = $link['name'] ?? $link['link'] ?? "Link-{$ifIndex}";
            $linkId = $link['linkId'] ?? $link['id'] ?? $ifIndex;
            $interface = $link['interface'] ?? $linkName;
            $state = $link['state'] ?? 'UNKNOWN';

            // Map VeloCloud states to standard operational status
            $operStatus = match(strtoupper($state)) {
                'STABLE', 'UP' => 'up',
                'DOWN', 'DEAD' => 'down',
                'UNSTABLE' => 'testing',
                default => 'unknown',
            };

            $adminStatus = ($link['serviceState'] ?? 'IN_SERVICE') === 'IN_SERVICE' ? 'up' : 'down';

            // Calculate speed from bpsOfBestPathRx/Tx (bits per second)
            $speed = 0;
            if (isset($link['bpsOfBestPathTx']) && isset($link['bpsOfBestPathRx'])) {
                $speed = max($link['bpsOfBestPathTx'], $link['bpsOfBestPathRx']);
            } elseif (isset($link['uplinkMbps'])) {
                $speed = $link['uplinkMbps'] * 1000000; // Convert Mbps to bps
            }

            // Extract label information from nested link object
            $linkInfo = $link['link'] ?? [];
            $displayName = $linkInfo['displayName'] ?? $link['displayName'] ?? null;
            $isp = $linkInfo['isp'] ?? null;
            $linkIp = $linkInfo['linkIpAddress'] ?? null;
            $interfaceName = $linkInfo['interface'] ?? $linkName;

            // Build ifAlias with ISP/carrier label and IP address
            $labelParts = [];
            if ($displayName && $displayName !== $linkName) {
                $labelParts[] = $displayName;
            } elseif ($isp) {
                $labelParts[] = $isp;
            }
            if ($linkIp) {
                $labelParts[] = $linkIp;
            }
            $ifAlias = !empty($labelParts) ? implode(' - ', $labelParts) : $linkName;

            $ports[] = [
                'ifIndex' => $ifIndex++,
                'ifName' => $linkName,
                'ifDescr' => $interfaceName,
                'ifType' => 'ethernetCsmacd',
                'ifOperStatus' => $operStatus,
                'ifAdminStatus' => $adminStatus,
                'ifSpeed' => $speed,
                'ifMtu' => 1500,
                'ifPhysAddress' => '',
                'ifAlias' => $ifAlias,
            ];
        }

        return $ports;
    }
}
