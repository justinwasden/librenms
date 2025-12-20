<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - NetworkPerformance Normalizer
 *
 * Capability: ports
 * Vendor: pure
 */
class NetworkPerformance extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$stats = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $stats;
        }

		foreach ($payload['items'] as $perf) { // Assuming $payload is the direct API response
		        $name = $perf['name'] ?? '';
		        $ifIndex = $this->stableIndexFromName($name);
		        $eth = $perf['eth'] ?? [];

		        // 1. Convert Bytes/sec rate to a Counter Delta
		        $rxBytes = (float)($eth['received_bytes_per_sec'] ?? 0) * $pollIntervalSec;
		        $txBytes = (float)($eth['transmitted_bytes_per_sec'] ?? 0) * $pollIntervalSec;

		        // 2. Convert Packet/sec rate to a Counter Delta
		        $rxPkts = (float)($eth['received_packets_per_sec'] ?? 0) * $pollIntervalSec;
		        $txPkts = (float)($eth['transmitted_packets_per_sec'] ?? 0) * $pollIntervalSec;

		        // 3. Aggregate Error Deltas (CRC/Frame errors are common RX errors)
		        $inErrors = (float)($eth['received_crc_errors_per_sec'] ?? 0) + (float)($eth['received_frame_errors_per_sec'] ?? 0);
		        $outErrors = (float)($eth['transmitted_dropped_errors_per_sec'] ?? 0); // Assuming dropped errors are the main TX error

		        $stats[$ifIndex] = [
		            // Note: These must be cast to integer or rounded, as DB expects BIGINT counters.
		            'ifInOctets' => (int) $rxBytes,
		            'ifOutOctets' => (int) $txBytes,
		            'ifInErrors' => (int) ($inErrors * $pollIntervalSec),
		            'ifOutErrors' => (int) ($outErrors * $pollIntervalSec),
		            'ifInUcastPkts' => (int) $rxPkts,
		            'ifOutUcastPkts' => (int) $txPkts,
		            'ifInDiscards' => 0, // Not provided by API, assume 0
		            'ifOutDiscards' => (int) ($eth['transmitted_dropped_errors_per_sec'] ?? 0 * $pollIntervalSec),
		            // The rest are complex/optional, set to 0 or null if not used
		        ];
		    }
		    return $stats;
    }
}
