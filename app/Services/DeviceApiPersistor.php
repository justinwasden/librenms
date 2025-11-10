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
        Log::debug("DeviceApiPersistor::savePorts called for device {$device->device_id} with " . count($ports) . " ports");
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
                    // Preserve existing ifIndex if port already exists, otherwise use provided value
                    'ifIndex'       => $portRow->ifIndex ?? ($p['ifIndex'] ?? null),
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
                // Generate deterministic sensor_oid if not provided
                $sensorType = $s['sensor_type'] ?? 'rest';
                $sensorIndex = (string) ($s['sensor_index'] ?? '');
                $defaultOid = $s['sensor_oid'] ?? ($device->os . '::' . $sensorIndex);

                $base = [
                    'device_id'                  => $device->device_id,
                    'sensor_class'               => $s['sensor_class'] ?? 'state',
                    'sensor_type'                => $sensorType,
                    'sensor_descr'               => $s['sensor_descr'] ?? '',
                    'sensor_index'               => $sensorIndex,
                    'sensor_oid'                 => $defaultOid,
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

                // Handle state sensors - create state index and translations
                if ($base['sensor_class'] === 'state' && isset($s['states']) && is_array($s['states'])) {
                    // Create unique state name based on sensor type and index
                    $stateName = $base['sensor_type'] . '_' . $base['sensor_index'];

                    // Get or create state_index
                    $stateIndex = DB::table('state_indexes')
                        ->where('state_name', $stateName)
                        ->first();

                    if (!$stateIndex) {
                        $stateIndexId = DB::table('state_indexes')->insertGetId([
                            'state_name' => $stateName,
                        ]);
                    } else {
                        $stateIndexId = $stateIndex->state_index_id;
                    }

                    // Link sensor to state_index via sensors_to_state_indexes table
                    $existingLink = DB::table('sensors_to_state_indexes')
                        ->where('sensor_id', $sensorId)
                        ->first();

                    if (!$existingLink) {
                        DB::table('sensors_to_state_indexes')->insert([
                            'sensor_id' => $sensorId,
                            'state_index_id' => $stateIndexId,
                        ]);
                    }

                    // Create state translations
                    foreach ($s['states'] as $state) {
                        $existingTranslation = DB::table('state_translations')
                            ->where('state_index_id', $stateIndexId)
                            ->where('state_value', $state['value'])
                            ->first();

                        if (!$existingTranslation) {
                            DB::table('state_translations')->insert([
                                'state_index_id' => $stateIndexId,
                                'state_descr' => $state['descr'],
                                'state_draw_graph' => $state['graph'],
                                'state_value' => $state['value'],
                                'state_generic_value' => $state['generic'],
                            ]);
                        }
                    }
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
                // Generate deterministic processor_oid if not provided
                $processorType = $pr['processor_type'] ?? 'rest';
                $processorIndex = (string) ($pr['processor_index'] ?? '');
                $defaultOid = $pr['processor_oid'] ?? ($device->os . '::processor::' . $processorIndex);

                $base = [
                    'device_id'        => $device->device_id,
                    'processor_type'   => $processorType,
                    'processor_oid'    => $defaultOid,
                    'processor_index'  => $processorIndex,
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
                // Skip if entPhysicalIndex is missing - it's a required field
                $physicalIndex = $e['entPhysicalIndex'] ?? null;
                if ($physicalIndex === null || $physicalIndex === '') {
                    Log::debug("Skipping inventory item for device {$device->device_id}: missing entPhysicalIndex", [
                        'item' => $e,
                    ]);
                    continue;
                }

                // Upsert entPhysical-like inventory
                $base = [
                    'device_id'                  => $device->device_id,
                    'entPhysicalIndex'           => $physicalIndex,
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
        Log::debug("DeviceApiPersistor::saveIpv4Addresses called for device {$device->device_id} with " . count($addresses) . " addresses");
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

                // Convert netmask to prefix length if needed
                $prefixlen = $addr['ipv4_prefixlen'] ?? $addr['prefixlen'] ?? null;
                if ($prefixlen === null && isset($addr['netmask'])) {
                    // Convert dotted quad netmask to prefix length
                    $prefixlen = self::netmaskToPrefixLength($addr['netmask']);
                }
                if ($prefixlen === null) {
                    $prefixlen = 24; // default
                }

                $base = [
                    'port_id'         => $portId,
                    'ipv4_address'    => $ipv4Address,
                    'ipv4_prefixlen'  => (int) $prefixlen,
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
     * Save VLANs (port groups, VLANs, etc.)
     */
    public static function saveVlans(Device $device, array $vlans): void
    {
        Log::debug("DeviceApiPersistor::saveVlans called for device {$device->device_id} with " . count($vlans) . " VLANs");

        foreach ($vlans as $vlan) {
            try {
                $vlanId = $vlan['vlan_vlan'] ?? null;
                $vlanDomain = $vlan['vlan_domain'] ?? 1;

                if ($vlanId === null) {
                    continue;
                }

                $base = [
                    'device_id' => $device->device_id,
                    'vlan_vlan' => $vlanId,
                    'vlan_domain' => $vlanDomain,
                    'vlan_name' => $vlan['vlan_name'] ?? "VLAN{$vlanId}",
                    'vlan_type' => $vlan['vlan_type'] ?? 'ethernet',
                ];

                // Check if VLAN already exists
                $existing = DB::table('vlans')
                    ->where('device_id', $device->device_id)
                    ->where('vlan_vlan', $vlanId)
                    ->where('vlan_domain', $vlanDomain)
                    ->first();

                if ($existing) {
                    DB::table('vlans')->where('vlan_id', $existing->vlan_id)->update($base);
                } else {
                    DB::table('vlans')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveVlans failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }

        // After saving VLANs, associate ports with VLANs for vCenter
        self::associatePortsWithVlans($device);

        // Also sync VLANs to managed ESXi hosts
        self::syncVlansToEsxiHosts($device, $vlans);
    }

    /**
     * Associate ports with VLANs for vCenter devices
     * Matches port's ifAlias (port group ID) with VLAN names via vCenter API
     */
    private static function associatePortsWithVlans(Device $device): void
    {
        // Only do this for vCenter devices
        if (!in_array($device->os, ['vmware', 'vsphere', 'vmware-vcsa'], true)) {
            return;
        }

        try {
            // Get vCenter client to fetch port groups
            $client = \App\ApiClients\DeviceApiClientFactory::make($device);
            if (!$client) {
                return;
            }

            // Fetch port groups from vCenter to get the mapping
            $portGroupsResponse = $client->get('vcenter/network');
            $portGroups = $portGroupsResponse['value'] ?? $portGroupsResponse;

            if (!is_array($portGroups)) {
                return;
            }

            // Create mapping: port group ID -> port group name
            $portGroupIdToName = [];
            foreach ($portGroups as $pg) {
                $networkId = $pg['network'] ?? null;
                $name = $pg['name'] ?? null;
                if ($networkId && $name) {
                    $portGroupIdToName[$networkId] = $name;
                }
            }

            // Get all ports and VLANs for this device
            $ports = DB::table('ports')
                ->where('device_id', $device->device_id)
                ->whereNotNull('ifAlias')
                ->where('ifAlias', '!=', '')
                ->get();

            $vlans = DB::table('vlans')
                ->where('device_id', $device->device_id)
                ->get()
                ->keyBy('vlan_name'); // Key by name for easy lookup

            $associated = 0;
            foreach ($ports as $port) {
                // ifAlias contains the port group ID (e.g., "dvportgroup-82377")
                $portGroupId = $port->ifAlias;

                // Look up the port group name from the ID
                $portGroupName = $portGroupIdToName[$portGroupId] ?? null;

                if (!$portGroupName) {
                    continue;
                }

                // Find the VLAN with this port group name
                $vlan = $vlans->get($portGroupName);

                if (!$vlan) {
                    continue;
                }

                // Check if association already exists
                $existing = DB::table('ports_vlans')
                    ->where('port_id', $port->port_id)
                    ->where('vlan', $vlan->vlan_vlan)
                    ->where('device_id', $device->device_id)
                    ->first();

                if (!$existing) {
                    DB::table('ports_vlans')->insert([
                        'device_id' => $device->device_id,
                        'port_id' => $port->port_id,
                        'vlan' => $vlan->vlan_vlan,
                        'baseport' => 0,
                        'priority' => 0,
                        'state' => 'active',
                        'cost' => 0,
                        'untagged' => 0,
                    ]);
                    $associated++;
                }
            }

            if ($associated > 0) {
                Log::debug("Associated {$associated} ports with VLANs for device {$device->device_id}");
            }

        } catch (\Throwable $e) {
            Log::warning("associatePortsWithVlans failed for device {$device->device_id}: {$e->getMessage()}");
        }
    }

    /**
     * Sync VLANs from vCenter to managed ESXi host devices
     * This ensures ESXi hosts show the portgroups in their VLAN tab
     */
    private static function syncVlansToEsxiHosts(Device $device, array $vlans): void
    {
        // Only do this for vCenter devices
        if (!in_array($device->os, ['vmware', 'vsphere', 'vmware-vcsa'], true)) {
            return;
        }

        try {
            // Find all ESXi devices to sync VLANs to
            // All ESXi devices in LibreNMS will receive portgroups from any vCenter
            $esxiDevices = DB::table('devices')
                ->whereIn('os', ['esxi', 'vmware-esxi'])
                ->get();

            if ($esxiDevices->isEmpty()) {
                return;
            }

            // Copy VLANs to each ESXi device
            foreach ($esxiDevices as $esxiDevice) {
                $copiedCount = 0;
                $updatedCount = 0;

                foreach ($vlans as $vlan) {
                    $vlanId = $vlan['vlan_vlan'] ?? null;
                    $vlanDomain = $vlan['vlan_domain'] ?? 1;

                    if ($vlanId === null) {
                        continue;
                    }

                    $base = [
                        'device_id' => $esxiDevice->device_id,
                        'vlan_vlan' => $vlanId,
                        'vlan_domain' => $vlanDomain,
                        'vlan_name' => $vlan['vlan_name'] ?? "VLAN{$vlanId}",
                        'vlan_type' => $vlan['vlan_type'] ?? 'ethernet',
                    ];

                    // Check if VLAN already exists for this ESXi host
                    $existing = DB::table('vlans')
                        ->where('device_id', $esxiDevice->device_id)
                        ->where('vlan_vlan', $vlanId)
                        ->where('vlan_domain', $vlanDomain)
                        ->first();

                    if ($existing) {
                        DB::table('vlans')->where('vlan_id', $existing->vlan_id)->update($base);
                        $updatedCount++;
                    } else {
                        DB::table('vlans')->insert($base);
                        $copiedCount++;
                    }
                }

                if ($copiedCount > 0 || $updatedCount > 0) {
                    Log::info("Synced VLANs from vCenter {$device->device_id} to ESXi host {$esxiDevice->device_id} ({$esxiDevice->hostname}): {$copiedCount} new, {$updatedCount} updated");
                }
            }

        } catch (\Throwable $e) {
            Log::warning("syncVlansToEsxiHosts failed for device {$device->device_id}: {$e->getMessage()}");
        }
    }

    /**
     * Save port traffic statistics (traffic counters and metrics)
     * Updates ports table with current counters and calculates rates/deltas
     */
    public static function savePortsStatistics(Device $device, array $statistics): void
    {
        Log::debug("DeviceApiPersistor::savePortsStatistics called for device {$device->device_id} with " . count($statistics) . " statistics records");
        foreach ($statistics as $stat) {
            try {
                // Find port_id from ifIndex, ifName, or direct port_id
                $portId = self::findPortId($device, $stat);
                if (!$portId) {
                    Log::debug("Skipping port statistics - no port_id found", [
                        'device_id' => $device->device_id,
                        'ifIndex' => $stat['ifIndex'] ?? 'unknown',
                        'ifName'  => $stat['ifName'] ?? 'unknown',
                    ]);
                    continue;
                }

                // Get existing port row
                $existingPort = DB::table('ports')->where('port_id', $portId)->first();
                if (!$existingPort) {
                    Log::debug("Port not found for statistics update", ['port_id' => $portId]);
                    continue;
                }

                $now = time();
                $poll_time = isset($stat['poll_time']) ? (int) $stat['poll_time'] : $now;
                $poll_period = isset($stat['poll_period']) ? (int) $stat['poll_period'] : ($existingPort->poll_time ? ($now - $existingPort->poll_time) : 300);
                if ($poll_period < 1) {
                    $poll_period = 300;
                }

                $updates = [
                    'poll_time' => $poll_time,
                    'poll_period' => $poll_period,
                ];

                // Accept rate-based fields even if counters are not provided
                $rateFields = [
                    'ifInOctets_rate', 'ifOutOctets_rate',
                    'ifInBits_rate', 'ifOutBits_rate',
                    'ifInUcastPkts_rate', 'ifOutUcastPkts_rate',
                    'ifInErrors_rate', 'ifOutErrors_rate',
                    'ifInNUcastPkts_rate', 'ifOutNUcastPkts_rate',
                    'ifInBroadcastPkts_rate', 'ifOutBroadcastPkts_rate',
                    'ifInMulticastPkts_rate', 'ifOutMulticastPkts_rate',
                    'ifInDiscards_rate', 'ifOutDiscards_rate',
                ];
                $hasRates = false;
                foreach ($rateFields as $rf) {
                    if (array_key_exists($rf, $stat)) {
                        $updates[$rf] = $stat[$rf];
                        if ($stat[$rf] !== null) {
                            $hasRates = true;
                        }
                    }
                }

                // Traditional counters (if provided). Compute deltas + rates against previous values.
                $counterFields = [
                    'ifInOctets', 'ifOutOctets',
                    'ifInUcastPkts', 'ifOutUcastPkts',
                    'ifInNUcastPkts', 'ifOutNUcastPkts',
                    'ifInDiscards', 'ifOutDiscards',
                    'ifInErrors', 'ifOutErrors',
                    'ifInBroadcastPkts', 'ifOutBroadcastPkts',
                    'ifInMulticastPkts', 'ifOutMulticastPkts',
                ];

                $hasCounters = false;
                foreach ($counterFields as $field) {
                    if (isset($stat[$field])) {
                        $hasCounters = true;
                        $currentValue = (int) $stat[$field];
                        $prevField = $field . '_prev';
                        $deltaField = $field . '_delta';
                        $rateField = $field . '_rate';

                        $previousValue = $existingPort->$field ?? null;
                        $delta = 0;
                        $rate = 0;

                        if ($previousValue !== null) {
                            if ($currentValue >= $previousValue) {
                                $delta = $currentValue - $previousValue;
                            } else {
                                // Counter wrapped - assume 64-bit
                                $maxCounter = PHP_INT_MAX;
                                $delta = ($maxCounter - $previousValue) + $currentValue;
                            }
                            if ($poll_period > 0) {
                                $rate = $delta / $poll_period;
                            }
                        }

                        $updates[$prevField] = $previousValue;
                        $updates[$field] = $currentValue;
                        $updates[$deltaField] = $delta;
                        $updates[$rateField] = $rate;
                    }
                }

                // If no bits rate provided but octet rates exist, derive bits rate
                if (!isset($updates['ifInBits_rate']) && isset($updates['ifInOctets_rate']) && $updates['ifInOctets_rate'] !== null) {
                    $updates['ifInBits_rate'] = $updates['ifInOctets_rate'] * 8;
                }
                if (!isset($updates['ifOutBits_rate']) && isset($updates['ifOutOctets_rate']) && $updates['ifOutOctets_rate'] !== null) {
                    $updates['ifOutBits_rate'] = $updates['ifOutOctets_rate'] * 8;
                }

                // Update the ports table with available metrics
                DB::table('ports')->where('port_id', $portId)->update($updates);

                // If counters are provided, update or create RRD with DERIVE datasets
                if ($hasCounters && isset($updates['ifInOctets'], $updates['ifOutOctets'])) {
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

                    $tags = [
                        'ifName' => $port->ifName ?? '',
                        'ifAlias' => $port->ifAlias ?? '',
                        'ifIndex' => $port->ifIndex ?? 0,
                        'port_descr_type' => $port->port_descr_type ?? 'ifAlias',
                        'rrd_name' => ['port-id' . $portId],
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

                    app('Datastore')->put($device->toArray(), 'ports', $tags, $fields);
                } elseif ($hasRates && isset($updates['ifInOctets_rate'], $updates['ifOutOctets_rate'])) {
                    // If only rates are provided (no counters), create RRD with GAUGE datasets
                    // This is for devices like PureStorage that only report rates
                    $port = DB::table('ports')->where('port_id', $portId)->first();

                    $rrd_def = RrdDefinition::make()
                        ->addDataset('INOCTETS', 'GAUGE', 0, 12500000000)
                        ->addDataset('OUTOCTETS', 'GAUGE', 0, 12500000000)
                        ->addDataset('INERRORS', 'GAUGE', 0, 12500000000)
                        ->addDataset('OUTERRORS', 'GAUGE', 0, 12500000000)
                        ->addDataset('INUCASTPKTS', 'GAUGE', 0, 12500000000)
                        ->addDataset('OUTUCASTPKTS', 'GAUGE', 0, 12500000000)
                        ->addDataset('INNUCASTPKTS', 'GAUGE', 0, 12500000000)
                        ->addDataset('OUTNUCASTPKTS', 'GAUGE', 0, 12500000000)
                        ->addDataset('INDISCARDS', 'GAUGE', 0, 12500000000)
                        ->addDataset('OUTDISCARDS', 'GAUGE', 0, 12500000000)
                        ->addDataset('INBROADCASTPKTS', 'GAUGE', 0, 12500000000)
                        ->addDataset('OUTBROADCASTPKTS', 'GAUGE', 0, 12500000000)
                        ->addDataset('INMULTICASTPKTS', 'GAUGE', 0, 12500000000)
                        ->addDataset('OUTMULTICASTPKTS', 'GAUGE', 0, 12500000000);

                    $tags = [
                        'ifName' => $port->ifName ?? '',
                        'ifAlias' => $port->ifAlias ?? '',
                        'ifIndex' => $port->ifIndex ?? 0,
                        'port_descr_type' => $port->port_descr_type ?? 'ifAlias',
                        'rrd_name' => ['port-id' . $portId],
                        'rrd_def' => $rrd_def,
                    ];

                    // Store rates directly as GAUGE values (bytes/sec)
                    $fields = [
                        'INOCTETS' => $updates['ifInOctets_rate'] ?? null,
                        'OUTOCTETS' => $updates['ifOutOctets_rate'] ?? null,
                        'INERRORS' => $updates['ifInErrors_rate'] ?? null,
                        'OUTERRORS' => $updates['ifOutErrors_rate'] ?? null,
                        'INUCASTPKTS' => $updates['ifInUcastPkts_rate'] ?? null,
                        'OUTUCASTPKTS' => $updates['ifOutUcastPkts_rate'] ?? null,
                        'INNUCASTPKTS' => $updates['ifInNUcastPkts_rate'] ?? null,
                        'OUTNUCASTPKTS' => $updates['ifOutNUcastPkts_rate'] ?? null,
                        'INDISCARDS' => $updates['ifInDiscards_rate'] ?? null,
                        'OUTDISCARDS' => $updates['ifOutDiscards_rate'] ?? null,
                        'INBROADCASTPKTS' => $updates['ifInBroadcastPkts_rate'] ?? null,
                        'OUTBROADCASTPKTS' => $updates['ifOutBroadcastPkts_rate'] ?? null,
                        'INMULTICASTPKTS' => $updates['ifInMulticastPkts_rate'] ?? null,
                        'OUTMULTICASTPKTS' => $updates['ifOutMulticastPkts_rate'] ?? null,
                    ];

                    app('Datastore')->put($device->toArray(), 'ports', $tags, $fields);
                }

                // Optionally store historical records when provided
                if (!empty($stat['store_history']) || !empty($stat['save_to_statistics'])) {
                    $statsRecord = [
                        'port_id' => $portId,
                        'timestamp' => now(),
                    ];

                    foreach ($counterFields as $field) {
                        if (isset($updates[$field])) {
                            $statsRecord[$field] = $updates[$field];
                        }
                        $rateField = $field . '_rate';
                        if (isset($updates[$rateField])) {
                            $statsRecord[$rateField] = $updates[$rateField];
                        }
                    }
                    foreach ($rateFields as $rf) {
                        if (isset($updates[$rf])) {
                            $statsRecord[$rf] = $updates[$rf];
                        }
                    }

                    try {
                        DB::table('ports_statistics')->insert($statsRecord);
                    } catch (\Throwable $e) {
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
     * Helper to find port_id from ifIndex, ifName, MAC address, or direct port_id
     */
    protected static function findPortId(Device $device, array $data): ?int
    {
        if (isset($data['port_id'])) {
            return (int) $data['port_id'];
        }

        // Try to find by ifIndex first
        if (isset($data['ifIndex'])) {
            $port = DB::table('ports')
                ->where('device_id', $device->device_id)
                ->where('ifIndex', $data['ifIndex'])
                ->first();
            if ($port) {
                return (int) $port->port_id;
            }
        }

        // Try to find by ifName
        if (isset($data['ifName'])) {
            $port = DB::table('ports')
                ->where('device_id', $device->device_id)
                ->where('ifName', $data['ifName'])
                ->first();
            if ($port) {
                return (int) $port->port_id;
            }
        }

        // Try to find by MAC address (context_name or ifPhysAddress)
        $macAddress = $data['context_name'] ?? $data['ifPhysAddress'] ?? null;
        if ($macAddress) {
            // Normalize MAC address format (remove colons, dashes, dots, make lowercase)
            $normalizedMac = strtolower(str_replace([':', '-', '.'], '', $macAddress));

            $port = DB::table('ports')
                ->where('device_id', $device->device_id)
                ->whereNotNull('ifPhysAddress')
                ->where('ifPhysAddress', '!=', '')
                ->get()
                ->first(function ($p) use ($normalizedMac) {
                    $portMac = strtolower(str_replace([':', '-', '.'], '', $p->ifPhysAddress ?? ''));
                    return $portMac === $normalizedMac;
                });

            if ($port) {
                return (int) $port->port_id;
            }
        }

        return null;
    }

    /**
     * Convert dotted quad netmask to prefix length
     * Example: "255.255.255.0" => 24
     */
    protected static function netmaskToPrefixLength(string $netmask): int
    {
        // Handle numeric netmask already (just return it)
        if (is_numeric($netmask)) {
            return (int) $netmask;
        }

        // Convert dotted quad to prefix length
        $long = ip2long($netmask);
        if ($long === false) {
            return 24; // default if invalid
        }

        $base = ip2long('255.255.255.255');
        return (int) (32 - log(($long ^ $base) + 1, 2));
    }

    /**
     * Save storage controllers
     */
    public static function saveControllers(Device $device, array $controllers): void
    {
        Log::debug("DeviceApiPersistor::saveControllers called for device {$device->device_id} with " . count($controllers) . " controllers");

        foreach ($controllers as $c) {
            try {
                $base = [
                    'device_id' => $device->device_id,
                    'controller_name' => $c['controller_name'] ?? 'Unknown',
                    'model' => $c['model'] ?? null,
                    'status' => $c['status'] ?? null,
                    'mode' => $c['mode'] ?? null,
                    'version' => $c['version'] ?? null,
                ];

                // Upsert by device_id + controller_name
                $existing = DB::table('storage_controllers')
                    ->where('device_id', $device->device_id)
                    ->where('controller_name', $base['controller_name'])
                    ->first();

                if ($existing) {
                    DB::table('storage_controllers')->where('id', $existing->id)->update($base);
                } else {
                    DB::table('storage_controllers')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveControllers failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Save storage volumes
     */
    public static function saveVolumes(Device $device, array $volumes): void
    {
        Log::debug("DeviceApiPersistor::saveVolumes called for device {$device->device_id} with " . count($volumes) . " volumes");

        foreach ($volumes as $v) {
            try {
                $base = [
                    'device_id' => $device->device_id,
                    'volume_name' => $v['volume_name'] ?? 'Unknown',
                    'volume_id' => $v['volume_id'] ?? null,
                    'read_bandwidth' => $v['read_bandwidth'] ?? 0,
                    'write_bandwidth' => $v['write_bandwidth'] ?? 0,
                    'read_iops' => $v['read_iops'] ?? 0,
                    'write_iops' => $v['write_iops'] ?? 0,
                    'read_latency' => $v['read_latency'] ?? null,
                    'write_latency' => $v['write_latency'] ?? null,
                    'size_bytes' => $v['size_bytes'] ?? 0,
                    'used_bytes' => $v['used_bytes'] ?? 0,
                ];

                // Upsert by device_id + volume_name
                $existing = DB::table('storage_volumes')
                    ->where('device_id', $device->device_id)
                    ->where('volume_name', $base['volume_name'])
                    ->first();

                if ($existing) {
                    DB::table('storage_volumes')->where('id', $existing->id)->update($base);
                } else {
                    DB::table('storage_volumes')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveVolumes failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Save storage hosts
     */
    public static function saveHosts(Device $device, array $hosts): void
    {
        Log::debug("DeviceApiPersistor::saveHosts called for device {$device->device_id} with " . count($hosts) . " hosts");

        foreach ($hosts as $h) {
            try {
                $base = [
                    'device_id' => $device->device_id,
                    'host_name' => $h['host_name'] ?? 'Unknown',
                    'personality' => $h['personality'] ?? null,
                    'host_group' => $h['host_group'] ?? null,
                    'is_local' => $h['is_local'] ?? false,
                    'port_connectivity_status' => $h['port_connectivity_status'] ?? null,
                    'port_connectivity_details' => $h['port_connectivity_details'] ?? null,
                    'iqn' => $h['iqn'] ?? null,
                    'wwns' => $h['wwns'] ?? null,
                ];

                // Upsert by device_id + host_name
                $existing = DB::table('storage_hosts')
                    ->where('device_id', $device->device_id)
                    ->where('host_name', $base['host_name'])
                    ->first();

                if ($existing) {
                    DB::table('storage_hosts')->where('id', $existing->id)->update($base);
                } else {
                    DB::table('storage_hosts')->insert($base);
                }
            } catch (\Throwable $e) {
                Log::warning("saveHosts failed for device {$device->device_id}: {$e->getMessage()}");
            }
        }
    }
}