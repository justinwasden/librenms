<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\RRD\RrdDefinition;

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
                    $sensorId = $existing->sensor_id;
                } else {
                    $sensorId = DB::table('sensors')->insertGetId($base);
                }

                // Create RRD file for sensor readings
                if ($sensorId && $base['sensor_current'] !== null) {
                    $rrd_def = RrdDefinition::make()
                        ->addDataset('sensor', $base['rrd_type']);

                    // Use sensor_index for RRD naming (consistent with LibreNMS convention)
                    $rrd_name = ['sensor', $base['sensor_class'], $base['sensor_type'], $base['sensor_index']];

                    $tags = [
                        'sensor_class' => $base['sensor_class'],
                        'sensor_type' => $base['sensor_type'],
                        'sensor_descr' => $base['sensor_descr'],
                        'sensor_index' => $base['sensor_index'],
                        'rrd_name' => $rrd_name,
                        'rrd_def' => $rrd_def,
                    ];

                    $fields = [
                        'sensor' => $base['sensor_current'],
                    ];

                    app('Datastore')->put($device->toArray(), 'sensor', $tags, $fields);
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
                    $processorId = $existing->processor_id;
                } else {
                    $processorId = DB::table('processors')->insertGetId($base);
                }

                // Create RRD file for processor usage
                if ($processorId && isset($base['processor_usage'])) {
                    $rrd_def = RrdDefinition::make()
                        ->addDataset('usage', 'GAUGE', 0, 125);

                    $tags = [
                        'processor_type' => $base['processor_type'],
                        'processor_index' => $base['processor_index'],
                        'rrd_name' => ['processor', $base['processor_type'], $base['processor_index']],
                        'rrd_def' => $rrd_def,
                    ];

                    $fields = [
                        'usage' => $base['processor_usage'],
                    ];

                    app('Datastore')->put($device->toArray(), 'processor', $tags, $fields);
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
                    $mempoolId = $existing->mempool_id;
                } else {
                    $mempoolId = DB::table('mempools')->insertGetId($base);
                }

                // Create RRD file for memory usage
                if ($mempoolId && isset($base['mempool_used'], $base['mempool_free'])) {
                    $rrd_def = RrdDefinition::make()
                        ->addDataset('used', 'GAUGE', 0)
                        ->addDataset('free', 'GAUGE', 0);

                    $tags = [
                        'mempool_type' => $base['mempool_type'],
                        'mempool_class' => $mp['mempool_class'] ?? 'system',
                        'mempool_index' => $base['mempool_index'],
                        'rrd_name' => ['mempool', $base['mempool_type'], $mp['mempool_class'] ?? 'system', $base['mempool_index']],
                        'rrd_def' => $rrd_def,
                    ];

                    $fields = [
                        'used' => $base['mempool_used'],
                        'free' => $base['mempool_free'],
                    ];

                    app('Datastore')->put($device->toArray(), 'mempool', $tags, $fields);
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
                    $storageId = $existing->storage_id;
                } else {
                    $storageId = DB::table('storage')->insertGetId($base);
                }

                // Create RRD file for storage metrics
                if ($storageId && isset($base['storage_used'], $base['storage_free'])) {
                    $rrd_def = RrdDefinition::make()
                        ->addDataset('used', 'GAUGE', 0)
                        ->addDataset('free', 'GAUGE', 0);

                    $tags = [
                        'storage_type' => $base['storage_type'],
                        'storage_index' => $base['storage_index'],
                        'storage_descr' => $base['storage_descr'],
                        'rrd_name' => ['storage', $base['type'], $base['storage_descr']],
                        'rrd_def' => $rrd_def,
                    ];

                    $fields = [
                        'used' => $base['storage_used'],
                        'free' => $base['storage_free'],
                    ];

                    app('Datastore')->put($device->toArray(), 'storage', $tags, $fields);
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

    public static function saveIpv4Networks(Device $device, array $networks): void
    {
        foreach ($networks as $network) {
            try {
                $ipv4Network = $network['ipv4_network'] ?? $network['network'] ?? null;
                if (!$ipv4Network) {
                    continue;
                }

                $base = [
                    'ipv4_network' => $ipv4Network,
                    'context_name' => $network['context_name'] ?? null,
                ];

                // Upsert by ipv4_network + context_name
                $existing = DB::table('ipv4_networks')
                    ->where('ipv4_network', $ipv4Network)
                    ->where(function ($query) use ($base) {
                        if ($base['context_name'] === null) {
                            $query->whereNull('context_name');
                        } else {
                            $query->where('context_name', $base['context_name']);
                        }
                    })
                    ->first();

                if ($existing) {
                    DB::table('ipv4_networks')->where('ipv4_network_id', $existing->ipv4_network_id)->update($base);
                } else {
                    DB::table('ipv4_networks')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveIpv4Networks failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Save port traffic statistics (traffic counters and metrics)
     * Updates ports table with current counters and calculates rates/deltas
     */
    public static function savePortsStatistics(Device $device, array $statistics): void
    {
        foreach ($statistics as $stat) {
            try {
                // Find port_id from ifIndex, ifName, or direct port_id
                $portId = self::findPortId($device, $stat);
                if (!$portId) {
                    Log::debug("Skipping port statistics - no port_id found", [
                        'device_id' => $device->device_id,
                        'ifIndex' => $stat['ifIndex'] ?? 'unknown',
                    ]);
                    continue;
                }

                // Get existing port data for delta/rate calculations
                $existingPort = DB::table('ports')->where('port_id', $portId)->first();
                if (!$existingPort) {
                    Log::debug("Port not found for statistics update", ['port_id' => $portId]);
                    continue;
                }

                $now = time();
                $poll_period = $stat['poll_period'] ?? ($existingPort->poll_time ? ($now - $existingPort->poll_time) : 300);

                // Ensure minimum poll period to avoid division by zero
                if ($poll_period < 1) {
                    $poll_period = 300;
                }

                // Build update array with traffic counters
                $updates = [
                    'poll_time' => $now,
                    'poll_period' => $poll_period,
                ];

                // Traffic counters - handle both raw values and deltas from API
                $counterFields = [
                    'ifInOctets', 'ifOutOctets',
                    'ifInUcastPkts', 'ifOutUcastPkts',
                    'ifInNUcastPkts', 'ifOutNUcastPkts',
                    'ifInDiscards', 'ifOutDiscards',
                    'ifInErrors', 'ifOutErrors',
                    'ifInBroadcastPkts', 'ifOutBroadcastPkts',
                    'ifInMulticastPkts', 'ifOutMulticastPkts',
                ];

                foreach ($counterFields as $field) {
                    if (isset($stat[$field])) {
                        $currentValue = (int) $stat[$field];
                        $prevField = $field . '_prev';
                        $deltaField = $field . '_delta';
                        $rateField = $field . '_rate';

                        // Calculate delta if we have previous value
                        $previousValue = $existingPort->$field ?? null;
                        $delta = 0;
                        $rate = 0;

                        if ($previousValue !== null) {
                            // Handle counter wrap (32-bit or 64-bit)
                            if ($currentValue >= $previousValue) {
                                $delta = $currentValue - $previousValue;
                            } else {
                                // Counter wrapped - assume 64-bit counter
                                $maxCounter = PHP_INT_MAX; // Typically 64-bit
                                $delta = ($maxCounter - $previousValue) + $currentValue;
                            }

                            // Calculate rate (per second)
                            if ($poll_period > 0) {
                                $rate = $delta / $poll_period;
                            }
                        }

                        // Update all fields
                        $updates[$prevField] = $previousValue; // Store previous value
                        $updates[$field] = $currentValue;       // Store current value
                        $updates[$deltaField] = $delta;         // Store delta
                        $updates[$rateField] = $rate;           // Store rate
                    }
                }

                // Update the ports table
                DB::table('ports')->where('port_id', $portId)->update($updates);

                // Create RRD file for port traffic statistics
                if (isset($updates['ifInOctets'], $updates['ifOutOctets'])) {
                    $port = DB::table('ports')->where('port_id', $portId)->first();

                    $rrd_def = RrdDefinition::make()
                        ->addDataset('INOCTETS', 'DERIVE', 0, 12500000000)
                        ->addDataset('OUTOCTETS', 'DERIVE', 0, 12500000000)
                        ->addDataset('INERRORS', 'DERIVE', 0, 12500000000)
                        ->addDataset('OUTERRORS', 'DERIVE', 0, 12500000000)
                        ->addDataset('INUCASTPKTS', 'DERIVE', 0, 12500000000)
                        ->addDataset('OUTUCASTPKTS', 'DERIVE', 0, 12500000000)
                        ->addDataset('INNUCASTPKTS', 'DERIVE', 0, 12500000000)
                        ->addDataset('OUTNUCASTPKTS', 'DERIVE', 0, 12500000000)
                        ->addDataset('INDISCARDS', 'DERIVE', 0, 12500000000)
                        ->addDataset('OUTDISCARDS', 'DERIVE', 0, 12500000000)
                        ->addDataset('INBROADCASTPKTS', 'DERIVE', 0, 12500000000)
                        ->addDataset('OUTBROADCASTPKTS', 'DERIVE', 0, 12500000000)
                        ->addDataset('INMULTICASTPKTS', 'DERIVE', 0, 12500000000)
                        ->addDataset('OUTMULTICASTPKTS', 'DERIVE', 0, 12500000000);

                    $rrd_name = ['port', 'port-id' . $portId];

                    $tags = [
                        'ifName' => $port->ifName ?? '',
                        'ifAlias' => $port->ifAlias ?? '',
                        'ifIndex' => $port->ifIndex ?? 0,
                        'port_descr_type' => $port->port_descr_type ?? 'ifAlias',
                        'rrd_name' => $rrd_name,
                        'rrd_def' => $rrd_def,
                    ];

                    $fields = [
                        'INOCTETS' => $updates['ifInOctets'],
                        'OUTOCTETS' => $updates['ifOutOctets'],
                        'INERRORS' => $updates['ifInErrors'] ?? null,
                        'OUTERRORS' => $updates['ifOutErrors'] ?? null,
                        'INUCASTPKTS' => $updates['ifInUcastPkts'] ?? null,
                        'OUTUCASTPKTS' => $updates['ifOutUcastPkts'] ?? null,
                        'INNUCASTPKTS' => $updates['ifInNUcastPkts'] ?? null,
                        'OUTNUCASTPKTS' => $updates['ifOutNUcastPkts'] ?? null,
                        'INDISCARDS' => $updates['ifInDiscards'] ?? null,
                        'OUTDISCARDS' => $updates['ifOutDiscards'] ?? null,
                        'INBROADCASTPKTS' => $updates['ifInBroadcastPkts'] ?? null,
                        'OUTBROADCASTPKTS' => $updates['ifOutBroadcastPkts'] ?? null,
                        'INMULTICASTPKTS' => $updates['ifInMulticastPkts'] ?? null,
                        'OUTMULTICASTPKTS' => $updates['ifOutMulticastPkts'] ?? null,
                    ];

                    // Add rate fields for dashboard display (not stored in RRD)
                    $fields['ifInOctets_rate'] = $updates['ifInOctets_rate'] ?? 0;
                    $fields['ifOutOctets_rate'] = $updates['ifOutOctets_rate'] ?? 0;
                    $fields['ifInUcastPkts_rate'] = $updates['ifInUcastPkts_rate'] ?? 0;
                    $fields['ifOutUcastPkts_rate'] = $updates['ifOutUcastPkts_rate'] ?? 0;
                    $fields['ifInErrors_rate'] = $updates['ifInErrors_rate'] ?? 0;
                    $fields['ifOutErrors_rate'] = $updates['ifOutErrors_rate'] ?? 0;
                    $fields['ifInBits_rate'] = isset($updates['ifInOctets_rate']) ? $updates['ifInOctets_rate'] * 8 : 0;
                    $fields['ifOutBits_rate'] = isset($updates['ifOutOctets_rate']) ? $updates['ifOutOctets_rate'] * 8 : 0;

                    app('Datastore')->put($device->toArray(), 'ports', $tags, $fields);
                }

                // Optionally store historical data in ports_statistics table
                if (!empty($stat['store_history']) || !empty($stat['save_to_statistics'])) {
                    $statsRecord = [
                        'port_id' => $portId,
                        'timestamp' => now(),
                    ];

                    // Copy relevant counter fields to statistics record
                    foreach ($counterFields as $field) {
                        if (isset($updates[$field])) {
                            $statsRecord[$field] = $updates[$field];
                        }
                        $rateField = $field . '_rate';
                        if (isset($updates[$rateField])) {
                            $statsRecord[$rateField] = $updates[$rateField];
                        }
                    }

                    // Insert into ports_statistics (if table exists and is being used)
                    try {
                        DB::table('ports_statistics')->insert($statsRecord);
                    } catch (\Throwable $e) {
                        // ports_statistics table may not exist or may not be in use
                        Log::debug("Could not insert into ports_statistics: {$e->getMessage()}");
                    }
                }

            } catch (\Throwable $e) {
                Log::warning("savePortsStatistics failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveHrDevice(Device $device, array $hrDevices): void
    {
        foreach ($hrDevices as $hrDev) {
            try {
                $base = [
                    'device_id'        => $device->device_id,
                    'hrDeviceIndex'    => $hrDev['hrDeviceIndex'] ?? $hrDev['index'] ?? null,
                    'hrDeviceDescr'    => $hrDev['hrDeviceDescr'] ?? $hrDev['descr'] ?? '',
                    'hrDeviceType'     => $hrDev['hrDeviceType'] ?? $hrDev['type'] ?? '',
                    'hrDeviceErrors'   => $hrDev['hrDeviceErrors'] ?? $hrDev['errors'] ?? 0,
                    'hrDeviceStatus'   => $hrDev['hrDeviceStatus'] ?? $hrDev['status'] ?? '',
                    'hrProcessorLoad'  => $hrDev['hrProcessorLoad'] ?? $hrDev['processor_load'] ?? null,
                ];

                if ($base['hrDeviceIndex'] === null) {
                    Log::debug("Skipping hrDevice - no index provided", [
                        'device_id' => $device->device_id,
                    ]);
                    continue;
                }

                // Upsert by device_id + hrDeviceIndex
                $existing = DB::table('hrDevice')
                    ->where('device_id', $device->device_id)
                    ->where('hrDeviceIndex', $base['hrDeviceIndex'])
                    ->first();

                if ($existing) {
                    DB::table('hrDevice')->where('hrDevice_id', $existing->hrDevice_id)->update($base);
                } else {
                    DB::table('hrDevice')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveHrDevice failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    public static function saveHrSystem(Device $device, array $hrSystemData): void
    {
        try {
            // hrSystem is a single record per device (not an array of items)
            // Accept both array of single item or direct object
            $data = is_array($hrSystemData) && isset($hrSystemData[0]) ? $hrSystemData[0] : $hrSystemData;

            $base = [
                'device_id'             => $device->device_id,
                'hrSystemNumUsers'      => $data['hrSystemNumUsers'] ?? $data['num_users'] ?? 0,
                'hrSystemProcesses'     => $data['hrSystemProcesses'] ?? $data['processes'] ?? 0,
                'hrSystemMaxProcesses'  => $data['hrSystemMaxProcesses'] ?? $data['max_processes'] ?? 0,
            ];

            // Upsert by device_id (only one record per device)
            $existing = DB::table('hrSystem')
                ->where('device_id', $device->device_id)
                ->first();

            if ($existing) {
                DB::table('hrSystem')->where('hrSystem_id', $existing->hrSystem_id)->update($base);
            } else {
                DB::table('hrSystem')->insert($base);
            }
        } catch (\Throwable $e) {
            Log::warning("saveHrSystem failed for device {$device->device_id}: {$e->getMessage()}");
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