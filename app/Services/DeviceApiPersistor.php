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
}