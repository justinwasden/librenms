<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - PortStatistics Normalizer
 *
 * Capability: statistics
 * Vendor: velocloud
 */
class PortStatistics extends BaseNormalizer
{
    protected string $capability = 'statistics';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $portStats = [];

        if (!is_array($data) || empty($data) || !isset($data[0])) {
            return [];
        }

        $ifIndex = 1;
        foreach ($data as $link) {
            $linkName = $link['name'] ?? "Link-{$ifIndex}";

            // VeloCloud returns aggregate statistics, not cumulative counters
            // bytesRx/bytesTx are aggregate values over a time window and can fluctuate
            // Use bpsOfBestPathRx/Tx (bits per second) for accurate rate reporting
            $bpsRx = $link['bpsOfBestPathRx'] ?? 0;
            $bpsTx = $link['bpsOfBestPathTx'] ?? 0;

            // Convert bits per second to bytes per second
            $bytesPerSecRx = $bpsRx / 8;
            $bytesPerSecTx = $bpsTx / 8;

            // Extract traffic statistics using rate-based fields
            $stats = [
                'ifIndex' => $ifIndex++,
                // Use rate fields instead of counters because VeloCloud provides rates, not cumulative counters
                'ifInOctets_rate' => $bytesPerSecRx,
                'ifOutOctets_rate' => $bytesPerSecTx,
                'ifInBits_rate' => $bpsRx,
                'ifOutBits_rate' => $bpsTx,
                // Packet rates could be derived from totalPackets/interval, but not reliable
                'ifInUcastPkts' => 0,
                'ifOutUcastPkts' => 0,
                'ifInErrors' => 0,
                'ifOutErrors' => 0,
                'ifInDiscards' => 0,
                'ifOutDiscards' => 0,
            ];

            $portStats[] = $stats;
        }

        return $portStats;
    }
}
