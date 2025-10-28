<?php
/**
 * rest-api.inc.php
 *
 * REST API Discovery Module
 * Discovers devices via REST API endpoints
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
 * @copyright  2025 LibreNMS
 * @author     Justin Wasden
 */

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
use LibreNMS\Util\DeviceApiSettings;

try {
    // Convert device array to Device model
    $deviceModel = Device::find($device['device_id']);

    if (!$deviceModel) {
        echo 'Device not found' . PHP_EOL;
        return;
    }

    // Check if REST API is enabled for this device
    if (!DeviceApiSettings::restEnabled($deviceModel)) {
        d_echo("REST API not enabled for device {$deviceModel->hostname}\n");
        return;
    }

    // Check circuit breaker
    if (DeviceApiSettings::shouldTripCircuitBreaker($deviceModel)) {
        d_echo("Circuit breaker tripped for device {$deviceModel->hostname} - too many errors\n");
        return;
    }

    echo 'REST API Discovery: ';

    try {
        $apiClient = DeviceApiClientFactory::make($deviceModel);

        if (!$apiClient) {
            echo 'No API client available' . PHP_EOL;
            return;
        }

        $capabilities = $apiClient->capabilities();

        // Discover sensors
        if (in_array('sensors', $capabilities)) {
            echo 'Sensors ';
            $sensors = $apiClient->fetchSensors($deviceModel);

            foreach ($sensors as $sensorData) {
                $sensorClass = $sensorData['sensor_class'] ?? null;
                $sensorType = $sensorData['sensor_type'] ?? 'rest-api';
                $sensorIndex = $sensorData['sensor_index'] ?? crc32($sensorData['sensor_descr'] ?? uniqid());
                $sensorDescr = $sensorData['sensor_descr'] ?? 'Unknown';
                $sensorCurrent = $sensorData['sensor_current'] ?? null;

                // Skip sensors with null or invalid values
                if ($sensorCurrent === null || $sensorCurrent === '' || $sensorClass === null) {
                    continue;
                }

                // Skip sensors with 0 value unless it's a valid zero for certain classes
                $validZeroClasses = ['state', 'uptime', 'count', 'delay'];
                if ($sensorCurrent == 0 && !in_array($sensorClass, $validZeroClasses)) {
                    continue;
                }

                // Discover the sensor (creates if not exists)
                discover_sensor(
                    null, // $valid - we'll handle cleanup separately
                    $sensorClass,
                    $device,
                    '.1.3.6.1.4.1.40482.' . $sensorIndex, // OID (fake for REST API)
                    $sensorIndex,
                    $sensorType,
                    $sensorDescr,
                    $sensorData['sensor_divisor'] ?? 1,
                    $sensorData['sensor_multiplier'] ?? 1,
                    $sensorData['sensor_limit'] ?? null,
                    $sensorData['sensor_limit_warn'] ?? null,
                    $sensorData['sensor_limit_low'] ?? null,
                    $sensorData['sensor_limit_low_warn'] ?? null,
                    $sensorCurrent,
                    $sensorData['rrd_type'] ?? 'GAUGE',
                    $sensorData['entPhysicalIndex'] ?? null,
                    $sensorData['entPhysicalIndex_measured'] ?? null,
                    $sensorData['sensor_prev'] ?? null,
                    $sensorData['user_func'] ?? null,
                    null, // $group
                    $sensorData['sensor_custom'] ?? 'No'
                );
            }
        }

        // Discover ports
        if (in_array('ports', $capabilities)) {
            echo 'Ports ';
            $ports = $apiClient->fetchPorts($deviceModel);

            foreach ($ports as $portData) {
                $ifIndex = $portData['ifIndex'] ?? null;
                $ifName = $portData['ifName'] ?? '';

                if ($ifIndex === null) {
                    continue;
                }

                // Check if port exists
                $port = \App\Models\Port::where('device_id', $deviceModel->device_id)
                    ->where('ifIndex', $ifIndex)
                    ->first();

                if (!$port) {
                    // Create new port
                    $port = new \App\Models\Port();
                    $port->device_id = $deviceModel->device_id;
                    $port->ifIndex = $ifIndex;
                    $port->ifName = $ifName;
                    $port->ifDescr = $portData['ifDescr'] ?? $ifName;
                    $port->ifAlias = $portData['ifAlias'] ?? '';
                    $port->ifType = $portData['ifType'] ?? 'ethernetCsmacd';
                    $port->ifOperStatus = $portData['ifOperStatus'] ?? 'unknown';
                    $port->ifAdminStatus = $portData['ifAdminStatus'] ?? 'unknown';
                    $port->ifSpeed = $portData['ifSpeed'] ?? 0;
                    $port->ifMtu = $portData['ifMtu'] ?? 1500;
                    $port->ifPhysAddress = $portData['ifPhysAddress'] ?? '';
                    $port->save();

                    echo '.';
                } else {
                    // Update port data during discovery
                    $port->ifName = $ifName;
                    $port->ifDescr = $portData['ifDescr'] ?? $ifName;
                    $port->ifType = $portData['ifType'] ?? $port->ifType;
                    $port->ifMtu = $portData['ifMtu'] ?? $port->ifMtu;
                    $port->ifPhysAddress = $portData['ifPhysAddress'] ?? $port->ifPhysAddress;
                    $port->save();

                    echo 'U';
                }
            }
        }

        // Discover mempools
        if (in_array('mempools', $capabilities)) {
            echo 'Mempools ';
            $mempools = $apiClient->fetchMempools($deviceModel);

            foreach ($mempools as $mempoolData) {
                $mempoolIndex = $mempoolData['mempool_index'] ?? null;
                $mempoolDescr = $mempoolData['mempool_descr'] ?? 'Memory';
                $mempoolTotal = $mempoolData['mempool_total'] ?? 0;

                if ($mempoolIndex === null || $mempoolTotal == 0) {
                    continue;
                }

                // Check if mempool exists
                $mempool = \App\Models\Mempool::where('device_id', $deviceModel->device_id)
                    ->where('mempool_index', $mempoolIndex)
                    ->first();

                if (!$mempool) {
                    // Create new mempool
                    $mempool = new \App\Models\Mempool();
                    $mempool->device_id = $deviceModel->device_id;
                    $mempool->mempool_index = $mempoolIndex;
                    $mempool->mempool_type = $mempoolData['mempool_type'] ?? 'rest-api';
                    $mempool->mempool_class = 'system';
                    $mempool->mempool_descr = $mempoolDescr;
                    $mempool->mempool_precision = $mempoolData['mempool_precision'] ?? 1;
                    $mempool->mempool_total = $mempoolTotal;
                    $mempool->save();

                    echo '.';
                }
            }
        }

        // Discover processors
        if (in_array('processors', $capabilities)) {
            echo 'Processors ';
            $processors = $apiClient->fetchProcessors($deviceModel);

            foreach ($processors as $processorData) {
                $processorIndex = $processorData['processor_index'] ?? null;
                $processorDescr = $processorData['processor_descr'] ?? 'CPU';

                if ($processorIndex === null) {
                    continue;
                }

                // Check if processor exists
                $processor = \App\Models\Processor::where('device_id', $deviceModel->device_id)
                    ->where('processor_index', $processorIndex)
                    ->first();

                if (!$processor) {
                    // Create new processor
                    $processor = new \App\Models\Processor();
                    $processor->device_id = $deviceModel->device_id;
                    $processor->processor_index = $processorIndex;
                    $processor->processor_type = $processorData['processor_type'] ?? 'rest-api';
                    $processor->processor_descr = $processorDescr;
                    $processor->processor_precision = $processorData['processor_precision'] ?? 1;
                    $processor->save();

                    echo '.';
                }
            }
        }

        // Discover inventory
        if (in_array('inventory', $capabilities)) {
            echo 'Inventory ';
            $inventory = $apiClient->fetchInventory($deviceModel);

            foreach ($inventory as $itemData) {
                $physicalIndex = $itemData['entPhysicalIndex'] ?? null;
                $physicalDescr = $itemData['entPhysicalDescr'] ?? '';

                if ($physicalIndex === null || empty($physicalDescr)) {
                    continue;
                }

                // Check if inventory item exists
                $item = \App\Models\EntPhysical::where('device_id', $deviceModel->device_id)
                    ->where('entPhysicalIndex', $physicalIndex)
                    ->first();

                if (!$item) {
                    // Create new inventory item
                    $item = new \App\Models\EntPhysical();
                    $item->device_id = $deviceModel->device_id;
                    $item->entPhysicalIndex = $physicalIndex;
                    $item->entPhysicalDescr = $physicalDescr;
                    $item->entPhysicalClass = $itemData['entPhysicalClass'] ?? 'other';
                    $item->entPhysicalName = $itemData['entPhysicalName'] ?? '';
                    $item->entPhysicalModelName = $itemData['entPhysicalModelName'] ?? '';
                    $item->entPhysicalSerialNum = $itemData['entPhysicalSerialNum'] ?? '';
                    $item->entPhysicalContainedIn = $itemData['entPhysicalContainedIn'] ?? 0;
                    $item->entPhysicalMfgName = $itemData['entPhysicalMfgName'] ?? '';
                    $item->entPhysicalParentRelPos = $itemData['entPhysicalParentRelPos'] ?? -1;
                    $item->entPhysicalVendorType = $itemData['entPhysicalVendorType'] ?? null;
                    $item->entPhysicalHardwareRev = $itemData['entPhysicalHardwareRev'] ?? '';
                    $item->entPhysicalFirmwareRev = $itemData['entPhysicalFirmwareRev'] ?? '';
                    $item->entPhysicalSoftwareRev = $itemData['entPhysicalSoftwareRev'] ?? '';
                    $item->entPhysicalIsFRU = $itemData['entPhysicalIsFRU'] ?? 'false';
                    $item->entPhysicalAlias = $itemData['entPhysicalAlias'] ?? '';
                    $item->entPhysicalAssetID = $itemData['entPhysicalAssetID'] ?? '';
                    $item->save();

                    echo '.';
                }
            }
        }

        echo PHP_EOL;

        // Record success
        DeviceApiSettings::recordSuccess($deviceModel, 0);

    } catch (\Exception $e) {
        echo 'FAILED: ' . $e->getMessage() . PHP_EOL;
        d_echo($e->getTraceAsString());
        DeviceApiSettings::recordError($deviceModel, $e->getMessage());
    }

} catch (\Exception $e) {
    echo 'REST API Discovery FAILED: ' . $e->getMessage() . PHP_EOL;
    d_echo($e->getTraceAsString());
}
