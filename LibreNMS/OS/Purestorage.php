<?php
/**
 * Purestorage.php
 *
 * PureStorage FlashArray OS definition
 * Uses REST API for all metrics collection (no SSH or limited SNMP)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       https://www.librenms.org
 *
 * @copyright  2025
 * @author     Justin Wasden
 */

declare(strict_types=1);

namespace LibreNMS\OS;

use Illuminate\Support\Facades\Log;
use LibreNMS\Util\Rrd;

class Purestorage extends Generic
{
    protected string $os = 'purestorage';

    /**
     * Poll OS-specific data
     * PureStorage primarily uses REST API for metrics
     * SNMP is only used for basic array-level performance metrics
     */
    public function poll_os(): void
    {
        // === SNMP Polling (Array-level metrics only) ===
        $this->pollSnmp();

        // Note: All detailed metrics (volumes, hosts, capacity, etc.)
        // are collected via REST API module, not here
    }

    /**
     * Poll basic array performance metrics via SNMP
     * Uses PURESTORAGE-MIB for array-level bandwidth, IOPS, and latency
     */
    private function pollSnmp(): void
    {
        $oids = [
            'purestorage_bandwidth' => [
                'read'  => '.1.3.6.1.4.1.40482.4.1.0',  // pureArrayReadBandwidth
                'write' => '.1.3.6.1.4.1.40482.4.2.0',  // pureArrayWriteBandwidth
            ],
            'purestorage_iops' => [
                'read'  => '.1.3.6.1.4.1.40482.4.3.0',  // pureArrayReadIOPS
                'write' => '.1.3.6.1.4.1.40482.4.4.0',  // pureArrayWriteIOPS
            ],
            'purestorage_latency' => [
                'read'  => '.1.3.6.1.4.1.40482.4.5.0',  // pureArrayReadLatency
                'write' => '.1.3.6.1.4.1.40482.4.6.0',  // pureArrayWriteLatency
            ],
        ];

        // Build array of all OIDs to query
        $snmp_oids = [];
        foreach ($oids as $rrd_file => $ds_oids) {
            foreach ($ds_oids as $ds => $oid) {
                $snmp_oids[] = $oid;
            }
        }

        // Query SNMP
        $snmp_data = $this->device->getSnmp()->getMulti($snmp_oids, '-OQUs', 'PURESTORAGE-MIB');

        if ($snmp_data) {
            foreach ($oids as $rrd_file => $ds_oids) {
                $rrd_values = [];
                $valid = true;

                // Extract values for this RRD file
                foreach ($ds_oids as $ds => $oid) {
                    if (isset($snmp_data[$oid]['value']) && is_numeric($snmp_data[$oid]['value'])) {
                        $rrd_values[] = $snmp_data[$oid]['value'];
                    } else {
                        $valid = false;
                        Log::debug("PureStorage SNMP: Missing or non-numeric value for {$oid} on device {$this->device->device_id}");
                        break;
                    }
                }

                // Update RRD if we have valid data
                if ($valid && count($rrd_values) > 0) {
                    Rrd::update($this->device, $rrd_file, $rrd_values);
                    $this->device->graphs['device_' . $rrd_file] = 1;
                    Log::debug("PureStorage SNMP: Updated {$rrd_file} for device {$this->device->device_id}");
                }
            }
        } else {
            Log::debug("PureStorage SNMP: No data returned for device {$this->device->device_id}");
        }
    }

    /**
     * Override to disable standard storage discovery
     * PureStorage storage metrics are collected via REST API
     */
    public function discoverStorage()
    {
        // Return empty collection - storage is handled by REST API
        return collect();
    }

    /**
     * Override to disable standard processor discovery
     * PureStorage does not expose CPU via SNMP
     */
    public function discoverProcessors()
    {
        // Return empty collection - no CPU metrics available
        return collect();
    }

    /**
     * Override to disable standard mempool discovery  
     * PureStorage does not expose memory via SNMP
     */
    public function discoverMempools()
    {
        // Return empty collection - no memory metrics available
        return collect();
    }
}
