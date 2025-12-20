<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - InterfaceStats Normalizer
 *
 * Capability: unknown
 * Vendor: fortigate
 */
class InterfaceStats extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$statistics = [];

        $results = $payload['results'] ?? $payload;
        if (!is_array($results)) {
            return $statistics;
        }

        foreach ($results as $iface) {
            $name = $iface['name'] ?? '';
            if (!$name) {
                continue;
            }

            $ifIndex = $this->stableIndexFromName($name);

            $statistics[] = [
                'ifIndex' => $ifIndex,
                'ifInOctets' => $iface['rx_bytes'] ?? $iface['link']['stats']['rx_bytes'] ?? 0,
                'ifOutOctets' => $iface['tx_bytes'] ?? $iface['link']['stats']['tx_bytes'] ?? 0,
                'ifInErrors' => $iface['rx_errors'] ?? $iface['link']['stats']['rx_errors'] ?? 0,
                'ifOutErrors' => $iface['tx_errors'] ?? $iface['link']['stats']['tx_errors'] ?? 0,
                'ifInUcastPkts' => $iface['rx_packets'] ?? $iface['link']['stats']['rx_packets'] ?? 0,
                'ifOutUcastPkts' => $iface['tx_packets'] ?? $iface['link']['stats']['tx_packets'] ?? 0,
                'ifInDiscards' => $iface['rx_dropped'] ?? $iface['link']['stats']['rx_dropped'] ?? 0,
                'ifOutDiscards' => $iface['tx_dropped'] ?? $iface['link']['stats']['tx_dropped'] ?? 0,
            ];
        }

        return $statistics;
    }
}
