<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DeviceApiPersistor
 *
 * Persists normalized records into LibreNMS tables.
 * Expected input arrays documented per capability.
 */
class DeviceApiPersistor
{
    public static function savePorts(Device $device, array $ports): void
    {
        foreach ($ports as $p) {
            if (!isset($p['ifIndex']) && !isset($p['ifName'])) {
                continue; // require at least one identifier
            }

            try {
                // Find existing port by ifIndex or ifName
                $portRow = null;
                if (isset($p['ifIndex'])) {
                    $portRow = DB::table('ports')
                        ->where('device_id', $device->device_id)
                        ->where('ifIndex', $p['ifIndex'])
                        ->first();
                }
                if (!$portRow && isset($p['ifName'])) {
                    $portRow = DB::table('ports')
                        ->where('device_id', $device->device_id)
                        ->where('ifName', $p['ifName'])
                        ->first();
                }

                $base = [
                    'device_id'     => $device->device_id,
                    'ifIndex'       => $p['ifIndex'] ?? ($portRow->ifIndex ?? null),
                    'ifName'        => $p['ifName'] ?? ($portRow->ifName ?? null),
                    'ifDescr'       => $p['ifDescr'] ?? ($portRow->ifDescr ?? null),
                    'ifType'        => $p['ifType'] ?? ($portRow->ifType ?? null),
                    'ifSpeed'       => $p['ifSpeed'] ?? ($portRow->ifSpeed ?? null),
                    'ifOperStatus'  => $p['ifOperStatus'] ?? ($portRow->ifOperStatus ?? null),
                    'ifAdminStatus' => $p['ifAdminStatus'] ?? ($portRow->ifAdminStatus ?? null),
                    'ifMtu'         => $p['ifMtu'] ?? ($portRow->ifMtu ?? null),
                    'ifPhysAddress' => $p['ifPhysAddress'] ?? ($portRow->ifPhysAddress ?? null),
                    'ifAlias'       => $p['ifAlias'] ?? ($portRow->ifAlias ?? null),
                ];

                if ($portRow) {
                    DB::table('ports')->where('port_id', $portRow->port_id)->update($base);
                } else {
                    DB::table('ports')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("savePorts failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveSensors(Device $device, array $sensors): void
    {
        foreach ($sensors as $s) {
            try {
                $base = [
                    'device_id'                  => $device->device_id,
                    'sensor_class'               => $s['sensor_class'] ?? 'state',
                    'sensor_type'                => $s['sensor_type'] ?? 'rest',
                    'sensor_descr'               => $s['sensor_descr'] ?? '',
                    'sensor_index'               => (string) ($s['sensor_index'] ?? ''),
                    'sensor_oid'                 => $s['sensor_oid'] ?? '.1.3.6.1.4.1.99999.1',
                    'sensor_current'             => $s['sensor_current'] ?? null,
                    'sensor_limit'               => $s['sensor_limit'] ?? null,
                    'sensor_limit_low'           => $s['sensor_limit_low'] ?? null,
                    'entPhysicalIndex'           => $s['entPhysicalIndex'] ?? null,
                    'entPhysicalIndex_measured'  => $s['entPhysicalIndex_measured'] ?? null,
                    'user_func'                  => $s['user_func'] ?? null,
                    'poller_type'                => 'rest',
                    'rrd_type'                   => $s['rrd_type'] ?? 'GAUGE',
                ];

                // Upsert by device_id + sensor_class + sensor_index
                $existing = DB::table('sensors')
                    ->where('device_id', $device->device_id)
                    ->where('sensor_class', $base['sensor_class'])
                    ->where('sensor_index', $base['sensor_index'])
                    ->first();

                if ($existing) {
                    DB::table('sensors')->where('sensor_id', $existing->sensor_id)->update($base);
                } else {
                    DB::table('sensors')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveSensors failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveProcessors(Device $device, array $processors): void
    {
        foreach ($processors as $pr) {
            try {
                $base = [
                    'device_id'        => $device->device_id,
                    'processor_type'   => $pr['processor_type'] ?? 'rest',
                    'processor_index'  => (string) ($pr['processor_index'] ?? ''),
                    'processor_descr'  => $pr['processor_descr'] ?? '',
                    'processor_usage'  => $pr['processor_usage'] ?? 0,
                    'processor_precision' => $pr['processor_precision'] ?? 1,
                ];

                $existing = DB::table('processors')
                    ->where('device_id', $device->device_id)
                    ->where('processor_type', $base['processor_type'])
                    ->where('processor_index', $base['processor_index'])
                    ->first();

                if ($existing) {
                    DB::table('processors')->where('processor_id', $existing->processor_id)->update($base);
                } else {
                    DB::table('processors')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveProcessors failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveMempools(Device $device, array $mps): void
    {
        foreach ($mps as $mp) {
            try {
                $base = [
                    'device_id'     => $device->device_id,
                    'mempool_type'  => $mp['mempool_type'] ?? 'rest',
                    'mempool_index' => (string) ($mp['mempool_index'] ?? ''),
                    'mempool_descr' => $mp['mempool_descr'] ?? '',
                    'mempool_used'  => $mp['mempool_used'] ?? 0,
                    'mempool_free'  => $mp['mempool_free'] ?? 0,
                    'mempool_total' => $mp['mempool_total'] ?? 0,
                    'mempool_perc'  => $mp['mempool_perc'] ?? 0,
                ];

                $existing = DB::table('mempools')
                    ->where('device_id', $device->device_id)
                    ->where('mempool_type', $base['mempool_type'])
                    ->where('mempool_index', $base['mempool_index'])
                    ->first();

                if ($existing) {
                    DB::table('mempools')->where('mempool_id', $existing->mempool_id)->update($base);
                } else {
                    DB::table('mempools')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveMempools failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveInventory(Device $device, array $inv): void
    {
        foreach ($inv as $e) {
            try {
                // Upsert entPhysical-like inventory
                $base = [
                    'device_id'                  => $device->device_id,
                    'entPhysicalIndex'           => $e['entPhysicalIndex'] ?? null,
                    'entPhysicalName'            => $e['name'] ?? ($e['entPhysicalName'] ?? ''),
                    'entPhysicalDescr'           => $e['descr'] ?? ($e['entPhysicalDescr'] ?? ''),
                    'entPhysicalClass'           => $e['class'] ?? ($e['entPhysicalClass'] ?? ''),
                    'entPhysicalSerialNum'       => $e['serial'] ?? ($e['entPhysicalSerialNum'] ?? ''),
                    'entPhysicalMfgName'         => $e['vendor'] ?? ($e['entPhysicalMfgName'] ?? ''),
                    'entPhysicalModelName'       => $e['model'] ?? ($e['entPhysicalModelName'] ?? ''),
                    'entPhysicalContainedIn'     => $e['parent'] ?? ($e['entPhysicalContainedIn'] ?? 0),
                    'entPhysicalParentRelPos'    => $e['entPhysicalParentRelPos'] ?? -1,
                    'entPhysicalVendorType'      => $e['entPhysicalVendorType'] ?? null,
                    'entPhysicalHardwareRev'     => $e['entPhysicalHardwareRev'] ?? '',
                    'entPhysicalFirmwareRev'     => $e['entPhysicalFirmwareRev'] ?? '',
                    'entPhysicalSoftwareRev'     => $e['entPhysicalSoftwareRev'] ?? '',
                    'entPhysicalIsFRU'           => $e['entPhysicalIsFRU'] ?? null,
                    'entPhysicalAlias'           => $e['entPhysicalAlias'] ?? '',
                    'entPhysicalAssetID'         => $e['entPhysicalAssetID'] ?? '',
                ];

                $existing = DB::table('entPhysical')
                    ->where('device_id', $device->device_id)
                    ->where('entPhysicalIndex', $base['entPhysicalIndex'])
                    ->first();

                if ($existing) {
                    DB::table('entPhysical')->where('entPhysical_id', $existing->entPhysical_id)->update($base);
                } else {
                    DB::table('entPhysical')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveInventory failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveTransceivers(Device $device, array $transceivers): void
    {
        foreach ($transceivers as $t) {
            try {
                // Find port_id from ifIndex or ifName if provided
                $portId = null;
                if (isset($t['ifIndex'])) {
                    $port = DB::table('ports')
                        ->where('device_id', $device->device_id)
                        ->where('ifIndex', $t['ifIndex'])
                        ->first();
                    $portId = $port->port_id ?? null;
                } elseif (isset($t['ifName'])) {
                    $port = DB::table('ports')
                        ->where('device_id', $device->device_id)
                        ->where('ifName', $t['ifName'])
                        ->first();
                    $portId = $port->port_id ?? null;
                } elseif (isset($t['port_id'])) {
                    $portId = $t['port_id'];
                }

                if (!$portId) {
                    Log::debug("Skipping transceiver - no port_id found", [
                        'device_id' => $device->device_id,
                        'index' => $t['index'] ?? 'unknown',
                    ]);
                    continue;
                }

                $base = [
                    'device_id'             => $device->device_id,
                    'port_id'               => $portId,
                    'index'                 => (string) ($t['index'] ?? $t['ifIndex'] ?? ''),
                    'entity_physical_index' => $t['entity_physical_index'] ?? $t['entPhysicalIndex'] ?? null,
                    'type'                  => $t['type'] ?? null,
                    'vendor'                => $t['vendor'] ?? null,
                    'oui'                   => $t['oui'] ?? null,
                    'model'                 => $t['model'] ?? null,
                    'revision'              => $t['revision'] ?? null,
                    'serial'                => $t['serial'] ?? null,
                    'date'                  => $t['date'] ?? null,
                    'ddm'                   => isset($t['ddm']) ? (bool) $t['ddm'] : null,
                    'encoding'              => $t['encoding'] ?? null,
                    'cable'                 => $t['cable'] ?? null,
                    'distance'              => $t['distance'] ?? null,
                    'wavelength'            => $t['wavelength'] ?? null,
                    'connector'             => $t['connector'] ?? null,
                    'channels'              => $t['channels'] ?? 1,
                    'updated_at'            => now(),
                ];

                // Upsert by device_id + port_id + index
                $existing = DB::table('transceivers')
                    ->where('device_id', $device->device_id)
                    ->where('port_id', $portId)
                    ->where('index', $base['index'])
                    ->first();

                if ($existing) {
                    DB::table('transceivers')->where('id', $existing->id)->update($base);
                } else {
                    $base['created_at'] = now();
                    DB::table('transceivers')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveTransceivers failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveStorage(Device $device, array $storage): void
    {
        foreach ($storage as $s) {
            try {
                $base = [
                    'device_id'           => $device->device_id,
                    'type'                => $s['type'] ?? 'rest',
                    'storage_index'       => (string) ($s['storage_index'] ?? $s['index'] ?? ''),
                    'storage_type'        => $s['storage_type'] ?? 'other',
                    'storage_descr'       => $s['storage_descr'] ?? $s['descr'] ?? '',
                    'storage_size'        => $s['storage_size'] ?? $s['size'] ?? 0,
                    'storage_size_oid'    => $s['storage_size_oid'] ?? null,
                    'storage_units'       => $s['storage_units'] ?? $s['units'] ?? 1,
                    'storage_used'        => $s['storage_used'] ?? $s['used'] ?? 0,
                    'storage_used_oid'    => $s['storage_used_oid'] ?? null,
                    'storage_free'        => $s['storage_free'] ?? $s['free'] ?? 0,
                    'storage_free_oid'    => $s['storage_free_oid'] ?? null,
                    'storage_perc'        => $s['storage_perc'] ?? $s['perc'] ?? 0,
                    'storage_perc_oid'    => $s['storage_perc_oid'] ?? null,
                    'storage_perc_warn'   => $s['storage_perc_warn'] ?? 60,
                ];

                // Upsert by device_id + type + storage_index
                $existing = DB::table('storage')
                    ->where('device_id', $device->device_id)
                    ->where('type', $base['type'])
                    ->where('storage_index', $base['storage_index'])
                    ->first();

                if ($existing) {
                    DB::table('storage')->where('storage_id', $existing->storage_id)->update($base);
                } else {
                    DB::table('storage')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveStorage failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveIpv4Addresses(Device $device, array $addresses): void
    {
        foreach ($addresses as $addr) {
            try {
                // Find port_id from ifIndex, ifName, or direct port_id
                $portId = self::findPortId($device, $addr);
                if (!$portId) {
                    Log::debug("Skipping IPv4 address - no port_id found", [
                        'device_id' => $device->device_id,
                        'address' => $addr['ipv4_address'] ?? 'unknown',
                    ]);
                    continue;
                }

                $ipv4Address = $addr['ipv4_address'] ?? $addr['address'] ?? null;
                if (!$ipv4Address) {
                    continue;
                }

                $base = [
                    'port_id'         => $portId,
                    'ipv4_address'    => $ipv4Address,
                    'ipv4_prefixlen'  => $addr['ipv4_prefixlen'] ?? $addr['prefixlen'] ?? $addr['netmask'] ?? 24,
                    'ipv4_network_id' => $addr['ipv4_network_id'] ?? 0,
                    'context_name'    => $addr['context_name'] ?? '',
                ];

                // Upsert by port_id + ipv4_address
                $existing = DB::table('ipv4_addresses')
                    ->where('port_id', $portId)
                    ->where('ipv4_address', $ipv4Address)
                    ->first();

                if ($existing) {
                    DB::table('ipv4_addresses')->where('ipv4_address_id', $existing->ipv4_address_id)->update($base);
                } else {
                    DB::table('ipv4_addresses')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveIpv4Addresses failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveIpv4Mac(Device $device, array $mappings): void
    {
        foreach ($mappings as $mapping) {
            try {
                // Find port_id from ifIndex, ifName, or direct port_id
                $portId = self::findPortId($device, $mapping);
                if (!$portId) {
                    Log::debug("Skipping IPv4/MAC mapping - no port_id found", [
                        'device_id' => $device->device_id,
                        'mac' => $mapping['mac_address'] ?? 'unknown',
                    ]);
                    continue;
                }

                $macAddress = $mapping['mac_address'] ?? $mapping['mac'] ?? null;
                $ipv4Address = $mapping['ipv4_address'] ?? $mapping['ip'] ?? null;

                if (!$macAddress || !$ipv4Address) {
                    continue;
                }

                $base = [
                    'port_id'      => $portId,
                    'device_id'    => $device->device_id,
                    'mac_address'  => $macAddress,
                    'ipv4_address' => $ipv4Address,
                    'context_name' => $mapping['context_name'] ?? '',
                ];

                // Upsert by port_id + mac_address + ipv4_address
                $existing = DB::table('ipv4_mac')
                    ->where('port_id', $portId)
                    ->where('mac_address', $macAddress)
                    ->where('ipv4_address', $ipv4Address)
                    ->first();

                if ($existing) {
                    DB::table('ipv4_mac')->where('id', $existing->id)->update($base);
                } else {
                    DB::table('ipv4_mac')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveIpv4Mac failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Helper to find port_id from ifIndex, ifName, or direct port_id
     */
    protected static function findPortId(Device $device, array $data): ?int
    {
        if (isset($data['port_id'])) {
            return (int) $data['port_id'];
        }

        if (isset($data['ifIndex'])) {
            $port = DB::table('ports')
                ->where('device_id', $device->device_id)
                ->where('ifIndex', $data['ifIndex'])
                ->first();
            return $port ? (int) $port->port_id : null;
        }

        if (isset($data['ifName'])) {
            $port = DB::table('ports')
                ->where('device_id', $device->device_id)
                ->where('ifName', $data['ifName'])
                ->first();
            return $port ? (int) $port->port_id : null;
        }

        return null;
    }
}