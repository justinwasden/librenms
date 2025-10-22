<?php

namespace App\Services\RestApi\Vendors;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PureStorageMapping extends VendorMappingInterface
{
    public static function getName(): string
    {
        return 'Pure Storage FlashArray';
    }

    public static function getDescription(): string
    {
        return 'Pure Storage FlashArray REST API v2.26 Mapping';
    }

    public static function getSupportedOs(): array
    {
        return ['pureflasharray', 'pure'];
    }

    public function mapDevice(array $data, Device $device): array
    {
        if (empty($data['items'])) {
            return [];
        }

        $arrayData = $data['items'][0] ?? [];

        return [
            'hostname' => $this->extractValue($arrayData, 'name') ?? $device->hostname,
            'sysName' => $this->extractValue($arrayData, 'name'),
            'version' => $this->extractValue($arrayData, 'version'),
            'hardware' => $this->extractValue($arrayData, 'model'),
            'os' => 'Purity//FA',
            'serial' => $this->extractValue($arrayData, 'id'),
            'location' => $this->extractValue($arrayData, 'time_zone'),
        ];
    }

    public function mapPorts(array $data, Device $device): array
    {
        if (empty($data['items'])) {
            return [];
        }

        $ports = [];

        foreach ($data['items'] as $item) {
            $port = [
                'ifName' => $item['name'] ?? null,
                'ifDescr' => $item['services'][0] ?? $item['name'] ?? null,
                'ifType' => $item['interface_type'] ?? 'ethernetCsmacd',
                'ifSpeed' => $item['speed'] ?? 0,
                'ifPhysAddress' => $item['eth']['mac_address'] ?? null,
                'ifAdminStatus' => ($item['enabled'] ?? false) ? 1 : 0,
                'ifOperStatus' => ($item['enabled'] ?? false) ? 1 : 0,
                'ifMtu' => $item['eth']['mtu'] ?? 1500,
                'ifAlias' => $item['eth']['address'] ?? null,
                'ifVlan' => $item['eth']['vlan'] ?? null,
            ];

            if (isset($item['eth']['address'])) {
                $port['ipv4_address'] = $item['eth']['address'];
                $port['ipv4_netmask'] = $item['eth']['netmask'] ?? '255.255.255.0';
            }

            $ports[] = $port;
        }

        return $ports;
    }

    public function mapStorage(array $data, Device $device): array
    {
        if (empty($data['items'])) {
            return [];
        }

        $storage = [];

        foreach ($data['items'] as $item) {
            $size = $item['space']['total_provisioned'] ?? 0;
            $used = $item['space']['total_physical'] ?? 0;
            $free = $size - $used;
            $perc = $size > 0 ? ($used / $size) * 100 : 0;

            $storage[] = [
                'storage_descr' => $item['name'] ?? null,
                'storage_type' => 'pure-volume',
                'storage_size' => $size,
                'storage_used' => $used,
                'storage_free' => $free,
                'storage_perc' => $perc,
                'data_reduction' => $item['space']['data_reduction'] ?? 0,
                'total_reduction' => $item['space']['total_reduction'] ?? 0,
                'snapshots' => $item['space']['snapshots'] ?? 0,
                'thin_provisioning' => $item['space']['thin_provisioning'] ?? 0,
            ];
        }

        return $storage;
    }

    public function mapSensors(array $data, Device $device): array
    {
        $sensors = [];

        if (isset($data['items'])) {
            // Array performance metrics
            if (isset($data['items'][0])) {
                $arrayData = $data['items'][0];

                $sensors[] = [
                    'sensor_descr' => 'Array Read Throughput',
                    'sensor_type' => 'gauge',
                    'sensor_value' => $arrayData['read_bytes_per_sec'] ?? 0,
                    'sensor_unit' => 'bytes/sec',
                ];

                $sensors[] = [
                    'sensor_descr' => 'Array Write Throughput',
                    'sensor_type' => 'gauge',
                    'sensor_value' => $arrayData['write_bytes_per_sec'] ?? 0,
                    'sensor_unit' => 'bytes/sec',
                ];

                $sensors[] = [
                    'sensor_descr' => 'Array Read IOPS',
                    'sensor_type' => 'gauge',
                    'sensor_value' => $arrayData['reads_per_sec'] ?? 0,
                    'sensor_unit' => 'ops/sec',
                ];

                $sensors[] = [
                    'sensor_descr' => 'Array Write IOPS',
                    'sensor_type' => 'gauge',
                    'sensor_value' => $arrayData['writes_per_sec'] ?? 0,
                    'sensor_unit' => 'ops/sec',
                ];

                $sensors[] = [
                    'sensor_descr' => 'Array Read Latency',
                    'sensor_type' => 'gauge',
                    'sensor_value' => $arrayData['usec_per_read_op'] ?? 0,
                    'sensor_unit' => 'microseconds',
                ];

                $sensors[] = [
                    'sensor_descr' => 'Array Write Latency',
                    'sensor_type' => 'gauge',
                    'sensor_value' => $arrayData['usec_per_write_op'] ?? 0,
                    'sensor_unit' => 'microseconds',
                ];
            }
        }

        return $sensors;
    }

    public function mapCustom(array $data, Device $device): array
    {
        return [];
    }
}
