<?php

declare(strict_types=1);

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure Storage - NetworkPerformanceToPortsStats Normalizer
 *
 * Converts per-second rate data from Pure Storage API into cumulative counter
 * values suitable for RRD DERIVE storage.
 *
 * The Pure Storage API returns instantaneous rates (bytes_per_sec, packets_per_sec)
 * but LibreNMS port graphs expect cumulative counters (ifInOctets, etc.) that
 * grow over time. This normalizer synthesizes counters by:
 * 1. Reading the current counter value from the database
 * 2. Adding (rate * poll_interval) to get the new counter value
 * 3. Returning synthetic counters that RRD can use with DERIVE
 *
 * Capability: ports_statistics
 * Vendor: pure
 */
class NetworkPerformanceToPortsStats extends BaseNormalizer
{
    protected string $capability = 'ports_statistics';
    protected string $vendor = 'pure';

    /**
     * Default poll interval in seconds (5 minutes)
     */
    private const DEFAULT_POLL_INTERVAL = 300;

    protected function doNormalize(Device $device, array $payload): array
    {
        $stats = [];
        $items = $payload['items'] ?? $payload;

        if (!is_array($items)) {
            return ['ports_statistics' => $stats];
        }

        // Get current port data to read existing counters and calculate deltas
        $existingPorts = DB::table('ports')
            ->where('device_id', $device->device_id)
            ->get(['port_id', 'ifIndex', 'ifName', 'ifInOctets', 'ifOutOctets', 'ifInErrors', 'ifOutErrors', 'ifInUcastPkts', 'ifOutUcastPkts', 'poll_time'])
            ->keyBy('ifName');

        // Calculate poll interval (default to 5 minutes if unknown)
        $currentTime = time();
        $pollInterval = self::DEFAULT_POLL_INTERVAL;

        // Try to get a more accurate poll interval from the first port with poll_time
        foreach ($existingPorts as $port) {
            if ($port->poll_time && $port->poll_time > 0) {
                $timeDiff = $currentTime - $port->poll_time;
                // Use the time difference if it's reasonable (between 1 minute and 15 minutes)
                if ($timeDiff > 60 && $timeDiff < 900) {
                    $pollInterval = $timeDiff;
                }
                break;
            }
        }

        foreach ($items as $perf) {
            $name = $perf['name'] ?? '';
            if (empty($name)) {
                continue;
            }

            // Get interface type specific stats
            $eth = $perf['eth'] ?? [];
            $fc = $perf['fc'] ?? [];

            // Extract rates (bytes and packets per second)
            $inBytesPerSec = $eth['received_bytes_per_sec'] ?? $fc['received_bytes_per_sec'] ?? 0;
            $outBytesPerSec = $eth['transmitted_bytes_per_sec'] ?? $fc['transmitted_bytes_per_sec'] ?? 0;
            $inErrorsPerSec = $eth['total_errors_per_sec'] ?? $fc['total_errors_per_sec'] ?? 0;
            $outErrorsPerSec = 0;  // Not separately tracked by Pure
            $inPktsPerSec = $eth['received_packets_per_sec'] ?? $fc['received_frames_per_sec'] ?? 0;
            $outPktsPerSec = $eth['transmitted_packets_per_sec'] ?? $fc['transmitted_frames_per_sec'] ?? 0;

            // Handle null values from API
            $inBytesPerSec = $inBytesPerSec ?? 0;
            $outBytesPerSec = $outBytesPerSec ?? 0;
            $inErrorsPerSec = $inErrorsPerSec ?? 0;
            $inPktsPerSec = $inPktsPerSec ?? 0;
            $outPktsPerSec = $outPktsPerSec ?? 0;

            // Calculate bytes/packets transferred during this poll interval
            $inBytesThisPoll = (int) ($inBytesPerSec * $pollInterval);
            $outBytesThisPoll = (int) ($outBytesPerSec * $pollInterval);
            $inErrorsThisPoll = (int) ($inErrorsPerSec * $pollInterval);
            $outErrorsThisPoll = (int) ($outErrorsPerSec * $pollInterval);
            $inPktsThisPoll = (int) ($inPktsPerSec * $pollInterval);
            $outPktsThisPoll = (int) ($outPktsPerSec * $pollInterval);

            // Get existing counters from database
            $existingPort = $existingPorts->get($name);
            if ($existingPort) {
                // Add to existing counters to create synthetic cumulative counters
                $newInOctets = ($existingPort->ifInOctets ?? 0) + $inBytesThisPoll;
                $newOutOctets = ($existingPort->ifOutOctets ?? 0) + $outBytesThisPoll;
                $newInErrors = ($existingPort->ifInErrors ?? 0) + $inErrorsThisPoll;
                $newOutErrors = ($existingPort->ifOutErrors ?? 0) + $outErrorsThisPoll;
                $newInPkts = ($existingPort->ifInUcastPkts ?? 0) + $inPktsThisPoll;
                $newOutPkts = ($existingPort->ifOutUcastPkts ?? 0) + $outPktsThisPoll;
                $ifIndex = $existingPort->ifIndex;
            } else {
                // No existing port - start counters from zero plus this poll's data
                $newInOctets = $inBytesThisPoll;
                $newOutOctets = $outBytesThisPoll;
                $newInErrors = $inErrorsThisPoll;
                $newOutErrors = $outErrorsThisPoll;
                $newInPkts = $inPktsThisPoll;
                $newOutPkts = $outPktsThisPoll;
                $ifIndex = $this->stableIndexFromName($name);
            }

            // Prevent counter overflow - wrap at 64-bit max
            $maxCounter = PHP_INT_MAX;
            $newInOctets = $newInOctets % $maxCounter;
            $newOutOctets = $newOutOctets % $maxCounter;
            $newInErrors = $newInErrors % $maxCounter;
            $newOutErrors = $newOutErrors % $maxCounter;
            $newInPkts = $newInPkts % $maxCounter;
            $newOutPkts = $newOutPkts % $maxCounter;

            $stats[] = [
                'ifIndex' => $ifIndex,
                'ifName' => $name,
                'ifInOctets' => $newInOctets,
                'ifOutOctets' => $newOutOctets,
                'ifInErrors' => $newInErrors,
                'ifOutErrors' => $newOutErrors,
                'ifInUcastPkts' => $newInPkts,
                'ifOutUcastPkts' => $newOutPkts,
            ];
        }

        return ['ports_statistics' => $stats];
    }
}
