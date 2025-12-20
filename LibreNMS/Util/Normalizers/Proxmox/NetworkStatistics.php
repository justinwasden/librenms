<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - NetworkStatistics Normalizer
 *
 * Capability: ports
 * Vendor: proxmox
 */
class NetworkStatistics extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
// Parse Proxmox RRD data for network statistics
        // Input: /nodes/{node}/rrddata?timeframe=hour
        // Output: structured array ['ports_statistics' => [...]] for DeviceApiPersistor::savePortsStatistics()
        //
        // NOTE: Proxmox rrddata provides NODE-LEVEL aggregate traffic (netin/netout),
        // not per-interface statistics. We apply this to vmbr0 (main bridge) or first active interface.

        $stats = [];

        if (!isset($payload['data']) || !is_array($payload['data']) || empty($payload['data'])) {
            return ['ports_statistics' => $stats];
        }

        // Get the latest data point (most recent)
        $latestData = end($payload['data']);
        if (!$latestData || !isset($latestData['time'])) {
            return ['ports_statistics' => $stats];
        }

        $pollTime = (int) $latestData['time'];
        $pollPeriod = 300; // Default 5 minute interval

        // Extract aggregate node network traffic
        $netin = isset($latestData['netin']) ? (float) $latestData['netin'] : null;
        $netout = isset($latestData['netout']) ? (float) $latestData['netout'] : null;

        \Log::debug('normalizeProxmoxNetworkStatistics: extracted values', [
            'netin' => $netin,
            'netout' => $netout,
            'poll_time' => $pollTime,
        ]);

        // Only create statistics if we have data
        if ($netin !== null || $netout !== null) {
            // Apply to vmbr0 (main bridge interface) by default
            // This represents the aggregate node traffic through the main bridge
            $stats[] = [
                'ifName' => 'vmbr0',  // Main Proxmox bridge interface
                'poll_time' => $pollTime,
                'poll_period' => $pollPeriod,
                'ifInOctets_rate' => $netin,
                'ifOutOctets_rate' => $netout,
                'ifInBits_rate' => $netin !== null ? $netin * 8 : null,
                'ifOutBits_rate' => $netout !== null ? $netout * 8 : null,
            ];

            \Log::debug('normalizeProxmoxNetworkStatistics: created statistics entry', [
                'stats_count' => count($stats),
                'stats' => $stats,
            ]);
        }

        \Log::debug('normalizeProxmoxNetworkStatistics: returning', [
            'stats_count' => count($stats),
        ]);

        return ['ports_statistics' => $stats];
    }
}
