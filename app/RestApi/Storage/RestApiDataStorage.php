<?php

namespace App\RestApi\Storage;

use App\Models\Device;
use App\Models\Port;
use App\Models\Sensors;
use App\Models\EntPhysical;
use App\Models\Ipv4Networks;
use App\Models\StorageTable;
use App\Models\Links;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Comprehensive REST API Data Storage Handler
 *
 * Handles storage of extracted metrics to all relevant LibreNMS database tables:
 * - devices: Array/device information
 * - ports: Network interfaces and performance metrics
 * - storage: Volumes, drives, provisioned space
 * - sensors: Performance metrics, hardware status, optical properties
 * - entPhysical: Hardware components, controllers, PSUs, fans
 * - ipv4_networks: Network configuration, subnets
 * - links: Array connections, replication links
 * - customAttributeValues: Custom attributes (parity, etc.)
 */
class RestApiDataStorage
{
    protected Device $device;
    protected array $mappingConfig;

    public function __construct(Device $device, array $mappingConfig = [])
    {
        $this->device = $device;
        $this->mappingConfig = $mappingConfig;
    }

    /**
     * Store extracted value to appropriate LibreNMS table
     *
     * Routing logic:
     * - Temperature, voltage, current, power, state → Sensors table
     * - Port/interface data → Ports table
     * - Volume/drive data → Storage table
     * - Hardware components → entPhysical table
     * - Network configuration → ipv4_networks table
     * - Array connections → links table
     * - Custom attributes → customAttributeValues table
     * - Device information → devices table
     */
    public function storeValue(
        string $table,
        string $field,
        $value,
        string $endpoint = '',
        array $context = []
    ): bool {
        try {
            $routedTable = $this->determineTargetTable($table, $field, $endpoint);

            return match ($routedTable) {
                'sensors' => $this->storeSensor($field, $value, $context),
                'ports' => $this->storePort($field, $value, $context),
                'storage' => $this->storeStorage($field, $value, $context),
                'entPhysical' => $this->storeHardwareComponent($field, $value, $context),
                'ipv4_networks' => $this->storeNetworkConfig($field, $value, $context),
                'links' => $this->storeLink($field, $value, $context),
                'devices' => $this->storeDeviceInfo($field, $value, $context),
                'customAttributeValues' => $this->storeCustomAttribute($field, $value, $context),
                default => false,
            };
        } catch (\Exception $e) {
            Log::error("RestApiDataStorage: Error storing {$field} to {$table}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * ========== SENSORS TABLE ==========
     *
     * Stores performance metrics and hardware sensors
     * - Temperature, voltage, current readings
     * - State indicators (up/down, ok/warning/critical)
     * - IOPS, throughput, latency metrics
     */
    protected function storeSensor(string $field, $value, array $context): bool
    {
        $sensorType = $this->determineSensorType($field);
        $sensorClass = $this->determineSensorClass($field);
        $sensorDescr = $context['sensor_descr'] ?? $this->generateSensorDescription($field);
        $sensorValue = $this->normalizeSensorValue($value, $sensorClass);

        if ($sensorValue === null) {
            return false;
        }

        // Find or create sensor
        $sensor = Sensors::firstOrCreate(
            [
                'device_id' => $this->device->device_id,
                'sensor_class' => $sensorClass,
                'sensor_type' => $sensorType,
                'sensor_descr' => $sensorDescr,
            ],
            [
                'sensor_current' => $sensorValue,
                'sensor_prev' => $sensorValue,
                'sensor_limit' => $context['sensor_limit'] ?? null,
                'sensor_limit_warn' => $context['sensor_limit_warn'] ?? null,
                'sensor_limit_low' => $context['sensor_limit_low'] ?? null,
                'sensor_limit_low_warn' => $context['sensor_limit_low_warn'] ?? null,
                'sensor_alert' => $context['sensor_alert'] ?? 1,
                'sensor_custom' => 'f',
                'entPhysical_id' => $context['entPhysical_id'] ?? null,
            ]
        );

        // Update current value
        $sensor->update([
            'sensor_current' => $sensorValue,
            'sensor_prev' => $sensor->sensor_current,
        ]);

        Log::debug("Device {$this->device->device_id}: Stored sensor {$sensorDescr} = {$sensorValue} {$sensorClass}");

        return true;
    }

    /**
     * ========== PORTS TABLE ==========
     *
     * Stores network interface information
     * - Interface names, types, speeds
     * - MAC addresses, IP addresses, VLANs
     * - Performance metrics (throughput, packets, errors)
     */
    protected function storePort(string $field, $value, array $context): bool
    {
        $portIdentifier = $context['if_identifier'] ?? $context['ifName'] ?? null;
        if (!$portIdentifier) {
            return false;
        }

        $port = Port::firstOrCreate(
            [
                'device_id' => $this->device->device_id,
                'ifName' => $portIdentifier,
            ],
            [
                'ifDescr' => $context['ifDescr'] ?? $portIdentifier,
                'ifType' => $context['ifType'] ?? 'ethernetCsmacd',
                'ifSpeed' => 0,
                'ifAdminStatus' => 'up',
                'ifOperStatus' => 'down',
                'ifLastChange' => 0,
                'ifAlias' => $context['ifAlias'] ?? '',
            ]
        );

        // Update port fields
        $updateData = [];
        switch ($field) {
            case 'ifSpeed':
            case 'ifInOctets':
            case 'ifOutOctets':
            case 'ifInUcastPkts':
            case 'ifOutUcastPkts':
            case 'ifInErrors':
            case 'ifOutErrors':
            case 'ifInDiscards':
            case 'ifOutDiscards':
            case 'ifAdminStatus':
            case 'ifOperStatus':
            case 'ifMtu':
                $updateData[$field] = is_numeric($value) ? (int) $value : $value;
                break;
            case 'ifPhysAddress':
                $updateData['ifPhysAddress'] = $this->normalizeMacAddress($value);
                break;
            case 'ifAlias':
            case 'ifDescr':
                $updateData[$field] = (string) $value;
                break;
            case 'ifVlan':
                $updateData['ifVlan'] = (int) $value;
                break;
        }

        if (!empty($updateData)) {
            $port->update($updateData);
        }

        // Store IP address separately if provided
        if ($field === 'ipv4_address' && isset($context['ipv4_netmask'])) {
            $this->storePortIpv4($port, $value, $context['ipv4_netmask']);
        }

        Log::debug("Device {$this->device->device_id}: Updated port {$portIdentifier} field {$field} = {$value}");

        return true;
    }

    /**
     * Store IPv4 address for a port
     */
    protected function storePortIpv4(Port $port, string $ipAddress, string $netmask): bool
    {
        try {
            DB::table('ipv4_networks')->updateOrInsert(
                [
                    'port_id' => $port->port_id,
                    'ipv4_address' => $ipAddress,
                ],
                [
                    'ipv4_netmask' => $netmask,
                    'ipv4_network' => $this->calculateNetwork($ipAddress, $netmask),
                    'context_name' => 'interface',
                ]
            );

            Log::debug("Port {$port->port_id}: Stored IPv4 {$ipAddress}/{$netmask}");
            return true;
        } catch (\Exception $e) {
            Log::error("Error storing port IPv4: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * ========== STORAGE TABLE ==========
     *
     * Stores volume and drive information
     * - Volume names, sizes, usage
     * - Data reduction ratios
     * - Drive types, protocols, status
     */
    protected function storeStorage(string $field, $value, array $context): bool
    {
        $storageDescr = $context['storage_descr'] ?? null;
        if (!$storageDescr) {
            return false;
        }

        $storageType = $context['storage_type'] ?? 'pure-volume';

        // Find or create storage entry
        $storage = DB::table('storage')->firstOrCreate(
            [
                'device_id' => $this->device->device_id,
                'storage_descr' => $storageDescr,
                'storage_type' => $storageType,
            ],
            [
                'storage_size' => 0,
                'storage_used' => 0,
                'storage_free' => 0,
                'storage_perc' => 0,
                'storage_units' => 1,
                'storage_mib' => null,
            ]
        );

        // Update storage fields
        $updateData = [];
        switch ($field) {
            case 'storage_size':
            case 'storage_used':
            case 'storage_free':
                $updateData[$field] = (int) $value;
                break;
            case 'storage_perc':
                $updateData[$field] = min(100, max(0, (int) $value));
                break;
            case 'data_reduction_ratio':
            case 'total_reduction_ratio':
            case 'thin_provisioning_ratio':
                $updateData[$field] = (float) $value;
                break;
            case 'snapshots_bytes':
                $updateData['snapshots_bytes'] = (int) $value;
                break;
            case 'volume_group':
            case 'pod_name':
                $updateData[$field] = (string) $value;
                break;
        }

        // Recalculate percentage if size or used changed
        if (isset($updateData['storage_size']) || isset($updateData['storage_used'])) {
            $size = $updateData['storage_size'] ?? $storage->storage_size;
            $used = $updateData['storage_used'] ?? $storage->storage_used;
            if ($size > 0) {
                $updateData['storage_free'] = $size - $used;
                $updateData['storage_perc'] = round(($used / $size) * 100, 2);
            }
        }

        if (!empty($updateData)) {
            DB::table('storage')->where('storage_id', $storage->storage_id)->update($updateData);
        }

        Log::debug("Device {$this->device->device_id}: Updated storage {$storageDescr} field {$field} = {$value}");

        return true;
    }

    /**
     * ========== ENTPHYSICAL TABLE ==========
     *
     * Stores hardware component information
     * - Controllers (CT0, CT1)
     * - Power supplies, fans
     * - Component status and versions
     */
    protected function storeHardwareComponent(string $field, $value, array $context): bool
    {
        $componentType = $context['component_type'] ?? 'other';
        $componentDescr = $context['component_descr'] ?? $context['sensor_descr'] ?? null;

        if (!$componentDescr) {
            return false;
        }

        // Find or create entPhysical entry
        $entPhysical = DB::table('entPhysical')->firstOrCreate(
            [
                'device_id' => $this->device->device_id,
                'entPhysicalDescr' => $componentDescr,
                'entPhysicalClass' => $this->mapComponentTypeToClass($componentType),
            ],
            [
                'entPhysicalType' => $componentType,
                'entPhysicalSerialNum' => $context['component_serial'] ?? '',
                'entPhysicalModelName' => $context['component_model'] ?? '',
                'entPhysicalContainedIn' => 0,
                'entPhysicalParentRelPos' => 0,
            ]
        );

        // Update entPhysical fields
        $updateData = [];
        switch ($field) {
            case 'component_status':
                $updateData['entPhysicalOperStatus'] = $this->mapStatusToOperStatus($value);
                $updateData['entPhysicalFirmwareRev'] = $context['component_version'] ?? '';
                break;
            case 'component_mode':
                $updateData['entPhysicalMfgName'] = (string) $value;
                break;
            case 'component_serial':
                $updateData['entPhysicalSerialNum'] = (string) $value;
                break;
            case 'component_model':
                $updateData['entPhysicalModelName'] = (string) $value;
                break;
            case 'component_version':
                $updateData['entPhysicalFirmwareRev'] = (string) $value;
                break;
        }

        if (!empty($updateData)) {
            DB::table('entPhysical')
                ->where('entPhysicalIndex', $entPhysical->entPhysicalIndex)
                ->update($updateData);
        }

        Log::debug("Device {$this->device->device_id}: Updated component {$componentDescr} field {$field} = {$value}");

        return true;
    }

    /**
     * ========== IPV4_NETWORKS TABLE ==========
     *
     * Stores network configuration
     * - Subnet information
     * - VLAN assignments
     */
    protected function storeNetworkConfig(string $field, $value, array $context): bool
    {
        $vlanName = $context['vlan_name'] ?? null;
        if (!$vlanName) {
            return false;
        }

        $updateData = [];
        switch ($field) {
            case 'ipv4_network':
                $updateData['ipv4_network'] = (string) $value;
                break;
            case 'vlan_id':
                $updateData['vlan_id'] = (int) $value;
                break;
            case 'vlan_name':
                $updateData['vlan_name'] = (string) $value;
                break;
        }

        if (empty($updateData)) {
            return false;
        }

        DB::table('ipv4_networks')->updateOrInsert(
            [
                'device_id' => $this->device->device_id,
                'vlan_name' => $vlanName,
            ],
            $updateData + [
                'context_name' => 'network',
                'device_id' => $this->device->device_id,
            ]
        );

        Log::debug("Device {$this->device->device_id}: Updated network config {$vlanName} field {$field} = {$value}");

        return true;
    }

    /**
     * ========== LINKS TABLE ==========
     *
     * Stores array connections and replication links
     * - Local and remote ports
     * - Link status and transport type
     */
    protected function storeLink(string $field, $value, array $context): bool
    {
        $remoteHostname = $context['remote_hostname'] ?? null;
        $localPort = $context['local_port'] ?? null;

        if (!$remoteHostname || !$localPort) {
            return false;
        }

        // Find remote device
        $remoteDevice = Device::where('hostname', $remoteHostname)->first();
        if (!$remoteDevice) {
            Log::warning("Remote device {$remoteHostname} not found for link");
            return false;
        }

        $updateData = [];
        switch ($field) {
            case 'link_status':
                $updateData['link_status'] = (string) $value;
                break;
            case 'link_transport':
                $updateData['link_transport'] = (string) $value;
                break;
            case 'remote_port':
                $updateData['remote_port'] = (string) $value;
                break;
        }

        if (empty($updateData)) {
            return false;
        }

        DB::table('links')->updateOrInsert(
            [
                'local_device_id' => $this->device->device_id,
                'local_port' => $localPort,
                'remote_device_id' => $remoteDevice->device_id,
            ],
            $updateData + [
                'local_device_id' => $this->device->device_id,
                'local_port' => $localPort,
                'remote_device_id' => $remoteDevice->device_id,
                'link_type' => $context['link_type'] ?? 'replication',
            ]
        );

        Log::debug("Device {$this->device->device_id}: Updated link to {$remoteHostname} field {$field} = {$value}");

        return true;
    }

    /**
     * ========== DEVICES TABLE ==========
     *
     * Stores device-level information
     * - Hostname, version, model
     * - Serial number, timezone
     */
    protected function storeDeviceInfo(string $field, $value, array $context): bool
    {
        $updateData = [];
        switch ($field) {
            case 'hostname':
            case 'sysName':
                $updateData['hostname'] = (string) $value;
                break;
            case 'version':
                $updateData['version'] = (string) $value;
                break;
            case 'os':
                $updateData['os'] = (string) $value;
                break;
            case 'hardware':
                $updateData['hardware'] = (string) $value;
                break;
            case 'serial':
                $updateData['serial'] = (string) $value;
                break;
            case 'location':
                $updateData['location'] = (string) $value;
                break;
            default:
                return false;
        }

        $this->device->update($updateData);

        Log::debug("Device {$this->device->device_id}: Updated device field {$field} = {$value}");

        return true;
    }

    /**
     * ========== CUSTOMATTRIBUTEVALUES TABLE ==========
     *
     * Stores custom attributes
     * - Parity settings
     * - Custom vendor-specific fields
     */
    protected function storeCustomAttribute(string $field, $value, array $context): bool
    {
        DB::table('customAttributeValues')->updateOrInsert(
            [
                'device_id' => $this->device->device_id,
                'attribute_name' => $field,
            ],
            [
                'attribute_value' => json_encode($value),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Log::debug("Device {$this->device->device_id}: Stored custom attribute {$field} = {$value}");

        return true;
    }

    /**
     * ========== HELPER METHODS ==========
     */

    /**
     * Determine which LibreNMS table should receive this data
     */
    protected function determineTargetTable(string $suggestedTable, string $field, string $endpoint): string
    {
        // Sensor fields - temperature, voltage, power, state, iops, latency, etc.
        if (in_array($field, [
            'temperature', 'voltage', 'current', 'power', 'status', 'state',
            'iops', 'throughput', 'latency', 'queue_latency',
            'read_iops', 'write_iops', 'read_throughput', 'write_throughput',
            'read_latency', 'write_latency', 'tx_power', 'rx_power', 'tx_bias',
            'tx_fault', 'rx_los', 'link_failures_per_sec',
        ])) {
            return 'sensors';
        }

        // Port/interface fields
        if (in_array($field, [
            'ifName', 'ifDescr', 'ifType', 'ifSpeed', 'ifPhysAddress',
            'ifAdminStatus', 'ifOperStatus', 'ifMtu', 'ifAlias', 'ifVlan',
            'ifInOctets', 'ifOutOctets', 'ifInUcastPkts', 'ifOutUcastPkts',
            'ifInErrors', 'ifOutErrors', 'ifInDiscards', 'ipv4_address', 'ipv4_netmask',
        ])) {
            return 'ports';
        }

        // Storage fields - volumes and drives
        if (in_array($field, [
            'storage_descr', 'storage_size', 'storage_used', 'storage_free', 'storage_perc',
            'storage_type', 'data_reduction_ratio', 'total_reduction_ratio', 'thin_provisioning_ratio',
            'snapshots_bytes', 'volume_group', 'pod_name', 'component_type', 'component_protocol',
        ])) {
            return 'storage';
        }

        // Hardware components
        if (in_array($field, [
            'component_model', 'component_version', 'component_mode', 'component_status',
            'component_serial', 'component_type', 'entPhysicalOperStatus',
        ])) {
            return 'entPhysical';
        }

        // Network configuration
        if (in_array($field, [
            'ipv4_network', 'vlan_id', 'vlan_name',
        ])) {
            return 'ipv4_networks';
        }

        // Links / connections
        if (in_array($field, [
            'local_port', 'remote_port', 'remote_hostname', 'link_transport', 'link_status',
        ])) {
            return 'links';
        }

        // Device information
        if (in_array($field, [
            'hostname', 'sysName', 'version', 'os', 'hardware', 'serial', 'location',
        ])) {
            return 'devices';
        }

        // Custom attributes
        if (in_array($field, [
            'parity', 'custom_attribute',
        ])) {
            return 'customAttributeValues';
        }

        // Fallback to suggested table
        return $suggestedTable;
    }

    /**
     * Determine sensor type (gauge, counter, absolute, etc.)
     */
    protected function determineSensorType(string $field): string
    {
        if (strpos($field, 'per_sec') !== false || strpos($field, 'rate') !== false) {
            return 'rate';
        } elseif (strpos($field, 'iops') !== false) {
            return 'gauge';
        } elseif (strpos($field, 'bytes') !== false && strpos($field, 'per_sec') === false) {
            return 'absolute';
        }

        return 'gauge';
    }

    /**
     * Determine sensor class for proper graphing
     */
    protected function determineSensorClass(string $field): string
    {
        if (strpos($field, 'temperature') !== false) {
            return 'temperature';
        } elseif (strpos($field, 'voltage') !== false) {
            return 'voltage';
        } elseif (strpos($field, 'current') !== false || strpos($field, 'bias') !== false) {
            return 'current';
        } elseif (strpos($field, 'power') !== false) {
            return 'power';
        } elseif (strpos($field, 'latency') !== false || strpos($field, 'usec') !== false) {
            return 'delay';
        } elseif (strpos($field, 'iops') !== false) {
            return 'counter';
        } elseif (strpos($field, 'throughput') !== false || strpos($field, 'bytes_per_sec') !== false) {
            return 'gauge';
        } elseif (strpos($field, 'status') !== false || strpos($field, 'state') !== false ||
                   strpos($field, 'fault') !== false || strpos($field, 'los') !== false) {
            return 'state';
        }

        return 'gauge';
    }

    /**
     * Generate descriptive sensor description
     */
    protected function generateSensorDescription(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    /**
     * Normalize sensor value to proper numeric type
     */
    protected function normalizeSensorValue($value, string $sensorClass)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($sensorClass === 'state') {
            // Map string states to numeric values
            return match (strtolower($value)) {
                'ok', 'up', 'ready', 'enabled', 'true' => 1,
                'warning' => 2,
                'critical', 'down', 'fault', 'disabled', 'false' => 0,
                default => is_numeric($value) ? (int) $value : null,
            };
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Normalize MAC address format
     */
    protected function normalizeMacAddress(string $mac): string
    {
        // Remove common delimiters and reformat to colon-separated
        $mac = strtolower(preg_replace('/[:\-\.]/', '', $mac));

        if (strlen($mac) !== 12) {
            return '';
        }

        return implode(':', str_split($mac, 2));
    }

    /**
     * Calculate network address from IP and netmask
     */
    protected function calculateNetwork(string $ip, string $netmask): string
    {
        try {
            $ipLong = ip2long($ip);
            $maskLong = ip2long($netmask);
            $network = long2ip($ipLong & $maskLong);

            // Calculate CIDR notation
            $maskBits = 32 - log(256 - (($maskLong >> 24) & 0xFF), 2);

            return "{$network}/{$maskBits}";
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Map component type to entPhysical class
     */
    protected function mapComponentTypeToClass(string $type): string
    {
        return match (strtolower($type)) {
            'controller' => 'chassis',
            'psu', 'power' => 'powerSupply',
            'fan' => 'fan',
            'sensor' => 'sensor',
            'transceiver', 'optical' => 'module',
            'drive', 'ssd', 'nvme' => 'storage',
            default => 'other',
        };
    }

    /**
     * Map status string to entPhysical OperStatus
     */
    protected function mapStatusToOperStatus(string $status): int
    {
        return match (strtolower($status)) {
            'ok', 'ready', 'up' => 1,
            'warning' => 2,
            'critical', 'fault', 'down' => 5,
            default => 0,
        };
    }
}