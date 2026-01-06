<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LibreNMS\RRD\RrdDefinition;

/**
 * DeviceApiPersistor - Streamlined version
 *
 * Persists normalized records into LibreNMS tables using a common sync pattern.
 * All methods maintain backward compatibility with static calls.
 */
class DeviceApiPersistor
{
    protected Device $device;

    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Generic sync method for upserting records
     *
     * @param string $table Database table name
     * @param array $records Records to sync
     * @param array $uniqueKeys Keys that identify a unique record (besides device_id)
     * @param callable $mapper Function to map input record to database columns
     * @param array $options Additional options (rrd, cleanup_type, id_column)
     * @return array IDs of synced records
     */
    protected function syncRecords(string $table, array $records, array $uniqueKeys, callable $mapper, array $options = []): array
    {
        $idColumn = $options['id_column'] ?? $table . '_id';
        $cleanupType = $options['cleanup_type'] ?? null;
        $rrdConfig = $options['rrd'] ?? null;
        $skipDiscoveredVia = $options['skip_discovered_via'] ?? false;
        $trackedIds = [];

        foreach ($records as $record) {
            try {
                $data = $mapper($record);
                if ($data === null) {
                    continue;
                }

                $data['device_id'] = $this->device->device_id;

                // Build unique key query
                $query = DB::table($table)->where('device_id', $this->device->device_id);
                foreach ($uniqueKeys as $key) {
                    if (isset($data[$key])) {
                        $query->where($key, $data[$key]);
                    }
                }
                $existing = $query->first();

                // Handle discovered_via field (only for tables that have it)
                if (!$skipDiscoveredVia) {
                    if (isset($existing->discovered_via)) {
                        $existingSource = $existing->discovered_via ?? 'snmp';
                        $data['discovered_via'] = ($existingSource === 'snmp') ? 'both' : $existingSource;
                    } elseif (!$existing && $this->tableHasColumn($table, 'discovered_via')) {
                        $data['discovered_via'] = 'api';
                    }
                }

                // Upsert
                if ($existing) {
                    DB::table($table)->where($idColumn, $existing->$idColumn)->update($data);
                    $id = $existing->$idColumn;
                } else {
                    $id = DB::table($table)->insertGetId($data);
                }

                $trackedIds[] = $id;

                // RRD storage
                if ($rrdConfig && $id) {
                    $this->storeRrd($data, $rrdConfig);
                }
            } catch (\Throwable $e) {
                Log::warning("syncRecords($table) failed: {$e->getMessage()}");
            }
        }

        // Cleanup stale API-discovered records
        if (!empty($trackedIds) && $cleanupType) {
            $this->cleanupStale($table, $idColumn, $trackedIds, $cleanupType);
        }

        return $trackedIds;
    }

    /**
     * Check if a table has a specific column (cached per request)
     */
    protected function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = "$table.$column";

        if (!isset($cache[$key])) {
            $cache[$key] = in_array($column, DB::getSchemaBuilder()->getColumnListing($table));
        }

        return $cache[$key];
    }

    protected function storeRrd(array $data, array $config): void
    {
        if (!isset($config['def']) || !isset($config['fields'])) {
            return;
        }

        $tags = $config['tags'] ?? [];
        $tags['rrd_def'] = $config['def'];

        $fields = [];
        foreach ($config['fields'] as $field => $key) {
            $fields[$field] = $data[$key] ?? 0;
        }

        app('Datastore')->put($this->device->toArray(), $config['measurement'] ?? 'unknown', $tags, $fields);
    }

    protected function cleanupStale(string $table, string $idColumn, array $keepIds, string $type): void
    {
        $deleted = DB::table($table)
            ->where('device_id', $this->device->device_id)
            ->whereIn('discovered_via', ['api', 'both'])
            ->whereNotIn($idColumn, $keepIds)
            ->when($type, fn($q) => $q->where(function($q) use ($type, $table) {
                // Type-specific filtering
                if (str_contains($table, 'sensor')) {
                    $q->where('sensor_type', $type);
                } elseif (str_contains($table, 'processor')) {
                    $q->where('processor_type', $type);
                } elseif (str_contains($table, 'mempool')) {
                    $q->where('mempool_type', $type);
                }
            }))
            ->delete();

        if ($deleted > 0) {
            Log::info("Deleted {$deleted} stale {$table} for device {$this->device->device_id}");
        }
    }

    // ===========================================
    // PUBLIC STATIC METHODS (Backward Compatible)
    // ===========================================

    public static function savePorts(Device $device, array $ports): void
    {
        $instance = new self($device);
        $instance->syncRecords('ports', $ports, ['ifIndex', 'ifName'], function ($p) {
            if (!isset($p['ifIndex']) && !isset($p['ifName'])) {
                return null;
            }
            return [
                'ifIndex'       => $p['ifIndex'] ?? null,
                'ifName'        => $p['ifName'] ?? null,
                'ifDescr'       => $p['ifDescr'] ?? $p['ifName'] ?? null,
                'ifType'        => $p['ifType'] ?? null,
                'ifSpeed'       => $p['ifSpeed'] ?? null,
                'ifOperStatus'  => $p['ifOperStatus'] ?? null,
                'ifAdminStatus' => $p['ifAdminStatus'] ?? null,
                'ifMtu'         => $p['ifMtu'] ?? null,
                'ifPhysAddress' => $p['ifPhysAddress'] ?? null,
                'ifAlias'       => $p['ifAlias'] ?? null,
                'ifVlan'        => $p['ifVlan'] ?? null,
                'deleted'       => 0,
            ];
        }, ['id_column' => 'port_id']);
    }

    public static function saveSensors(Device $device, array $sensors): void
    {
        $instance = new self($device);
        $instance->syncRecords('sensors', $sensors, ['sensor_class', 'sensor_index'], function ($s) use ($device) {
            if (empty($s['sensor_descr'])) {
                return null;
            }
            $sensorType = $s['sensor_type'] ?? 'rest';
            $sensorIndex = (string) ($s['sensor_index'] ?? '');
            return [
                'sensor_class'   => $s['sensor_class'] ?? 'state',
                'sensor_type'    => $sensorType,
                'sensor_descr'   => $s['sensor_descr'] ?? '',
                'sensor_index'   => $sensorIndex,
                'sensor_oid'     => $s['sensor_oid'] ?? ($device->os . '::' . $sensorIndex),
                'sensor_current' => $s['sensor_current'] ?? null,
                'sensor_limit'   => $s['sensor_limit'] ?? null,
                'sensor_limit_low' => $s['sensor_limit_low'] ?? null,
                'entPhysicalIndex' => $s['entPhysicalIndex'] ?? null,
                'entPhysicalIndex_measured' => $s['entPhysicalIndex_measured'] ?? null,
                'user_func'      => $s['user_func'] ?? null,
                'poller_type'    => 'rest',
                'rrd_type'       => $s['rrd_type'] ?? 'GAUGE',
            ];
        }, ['id_column' => 'sensor_id', 'cleanup_type' => 'rest']);
    }

    public static function saveProcessors(Device $device, array $processors): void
    {
        $instance = new self($device);
        $rrdDef = RrdDefinition::make()->addDataset('usage', 'GAUGE', 0, 125);

        $instance->syncRecords('processors', $processors, ['processor_type', 'processor_index'], function ($pr) use ($device) {
            $processorType = $pr['processor_type'] ?? 'rest';
            $processorIndex = (string) ($pr['processor_index'] ?? '');
            return [
                'processor_type'   => $processorType,
                'processor_oid'    => $pr['processor_oid'] ?? ($device->os . '::processor::' . $processorIndex),
                'processor_index'  => $processorIndex,
                'processor_descr'  => $pr['processor_descr'] ?? '',
                'processor_usage'  => $pr['processor_usage'] ?? 0,
                'processor_precision' => $pr['processor_precision'] ?? 1,
            ];
        }, [
            'id_column' => 'processor_id',
            'cleanup_type' => 'rest',
            'rrd' => [
                'measurement' => 'processor',
                'def' => $rrdDef,
                'fields' => ['usage' => 'processor_usage'],
                'tags' => [],
            ],
        ]);
    }

    public static function saveMempools(Device $device, array $mempools): void
    {
        $instance = new self($device);
        $rrdDef = RrdDefinition::make()
            ->addDataset('used', 'GAUGE', 0)
            ->addDataset('free', 'GAUGE', 0);

        $instance->syncRecords('mempools', $mempools, ['mempool_type', 'mempool_index'], function ($mp) {
            return [
                'mempool_type'  => $mp['mempool_type'] ?? 'rest',
                'mempool_index' => (string) ($mp['mempool_index'] ?? ''),
                'mempool_descr' => $mp['mempool_descr'] ?? '',
                'mempool_used'  => $mp['mempool_used'] ?? 0,
                'mempool_free'  => $mp['mempool_free'] ?? 0,
                'mempool_total' => $mp['mempool_total'] ?? 0,
                'mempool_perc'  => $mp['mempool_perc'] ?? 0,
            ];
        }, [
            'id_column' => 'mempool_id',
            'cleanup_type' => 'rest',
            'rrd' => [
                'measurement' => 'mempool',
                'def' => $rrdDef,
                'fields' => ['used' => 'mempool_used', 'free' => 'mempool_free'],
            ],
        ]);
    }

    public static function saveStorage(Device $device, array $storage): void
    {
        $instance = new self($device);
        $rrdDef = RrdDefinition::make()
            ->addDataset('used', 'GAUGE', 0)
            ->addDataset('free', 'GAUGE', 0);

        $instance->syncRecords('storage', $storage, ['storage_type', 'storage_index'], function ($st) {
            return [
                'storage_type'  => $st['storage_type'] ?? 'rest',
                'storage_index' => (string) ($st['storage_index'] ?? ''),
                'storage_descr' => $st['storage_descr'] ?? '',
                'storage_used'  => $st['storage_used'] ?? 0,
                'storage_free'  => $st['storage_free'] ?? 0,
                'storage_size'  => $st['storage_size'] ?? 0,
                'storage_perc'  => $st['storage_perc'] ?? 0,
                'storage_units' => $st['storage_units'] ?? 1,
            ];
        }, [
            'id_column' => 'storage_id',
            'rrd' => [
                'measurement' => 'storage',
                'def' => $rrdDef,
                'fields' => ['used' => 'storage_used', 'free' => 'storage_free'],
            ],
        ]);
    }

    public static function saveInventory(Device $device, array $inventory): void
    {
        $instance = new self($device);
        $instance->syncRecords('entPhysical', $inventory, ['entPhysicalIndex'], function ($inv) {
            return [
                'entPhysicalIndex'        => $inv['entPhysicalIndex'] ?? 0,
                'entPhysicalDescr'        => $inv['entPhysicalDescr'] ?? '',
                'entPhysicalClass'        => $inv['entPhysicalClass'] ?? 'other',
                'entPhysicalName'         => $inv['entPhysicalName'] ?? '',
                'entPhysicalModelName'    => $inv['entPhysicalModelName'] ?? '',
                'entPhysicalSerialNum'    => $inv['entPhysicalSerialNum'] ?? '',
                'entPhysicalContainedIn'  => $inv['entPhysicalContainedIn'] ?? 0,
                'entPhysicalMfgName'      => $inv['entPhysicalMfgName'] ?? '',
                'entPhysicalParentRelPos' => $inv['entPhysicalParentRelPos'] ?? -1,
                'entPhysicalVendorType'   => $inv['entPhysicalVendorType'] ?? '',
                'entPhysicalHardwareRev'  => $inv['entPhysicalHardwareRev'] ?? '',
                'entPhysicalFirmwareRev'  => $inv['entPhysicalFirmwareRev'] ?? '',
                'entPhysicalSoftwareRev'  => $inv['entPhysicalSoftwareRev'] ?? '',
                'entPhysicalIsFRU'        => $inv['entPhysicalIsFRU'] ?? 'false',
                'entPhysicalAlias'        => $inv['entPhysicalAlias'] ?? '',
                'entPhysicalAssetID'      => $inv['entPhysicalAssetID'] ?? '',
            ];
        }, ['id_column' => 'entPhysical_id']);
    }

    public static function saveTransceivers(Device $device, array $transceivers): void
    {
        $instance = new self($device);
        $instance->syncRecords('ports_transceivers', $transceivers, ['port_id', 'index'], function ($tr) {
            return [
                'port_id'      => $tr['port_id'] ?? 0,
                'index'        => $tr['index'] ?? 0,
                'supply'       => $tr['supply'] ?? null,
                'connector'    => $tr['connector'] ?? null,
                'encoding'     => $tr['encoding'] ?? null,
                'cable'        => $tr['cable'] ?? null,
                'distance'     => $tr['distance'] ?? null,
                'wavelength'   => $tr['wavelength'] ?? null,
                'type'         => $tr['type'] ?? null,
                'vendor'       => $tr['vendor'] ?? null,
                'oui'          => $tr['oui'] ?? null,
                'model'        => $tr['model'] ?? null,
                'revision'     => $tr['revision'] ?? null,
                'serial'       => $tr['serial'] ?? null,
                'date'         => $tr['date'] ?? null,
                'ddm'          => $tr['ddm'] ?? null,
                'entity_physical_index' => $tr['entity_physical_index'] ?? null,
            ];
        }, ['id_column' => 'id']);
    }

    public static function saveIpv4Addresses(Device $device, array $addresses): void
    {
        $instance = new self($device);
        foreach ($addresses as $addr) {
            if (empty($addr['ipv4_address'])) {
                continue;
            }
            try {
                // Find port
                $port = DB::table('ports')
                    ->where('device_id', $device->device_id)
                    ->where(function ($q) use ($addr) {
                        $q->where('ifIndex', $addr['ifIndex'] ?? null)
                          ->orWhere('ifName', $addr['ifName'] ?? null);
                    })
                    ->first();

                if (!$port) {
                    continue;
                }

                // Get or create network ID if not provided
                $networkId = $addr['ipv4_network_id'] ?? null;
                if ($networkId === null || $networkId === '') {
                    // Calculate network address from IP and prefix
                    $ip = $addr['ipv4_address'];
                    $prefix = $addr['ipv4_prefixlen'] ?? 24;
                    $network = long2ip(ip2long($ip) & (0xFFFFFFFF << (32 - $prefix)));
                    $networkCidr = "$network/$prefix";

                    // Find or create network
                    $existingNetwork = DB::table('ipv4_networks')
                        ->where('ipv4_network', $networkCidr)
                        ->first();

                    if ($existingNetwork) {
                        $networkId = $existingNetwork->ipv4_network_id;
                    } else {
                        $networkId = DB::table('ipv4_networks')->insertGetId([
                            'ipv4_network' => $networkCidr,
                            'context_name' => $addr['context_name'] ?? '',
                        ]);
                    }
                }

                DB::table('ipv4_addresses')->updateOrInsert(
                    [
                        'ipv4_address' => $addr['ipv4_address'],
                        'port_id' => $port->port_id,
                    ],
                    [
                        'ipv4_prefixlen' => $addr['ipv4_prefixlen'] ?? 24,
                        'ipv4_network_id' => $networkId,
                        'context_name' => $addr['context_name'] ?? '',
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("saveIpv4Addresses failed: {$e->getMessage()}");
            }
        }
    }

    public static function saveVlans(Device $device, array $vlans): void
    {
        $instance = new self($device);
        $trackedIds = [];

        foreach ($vlans as $vlan) {
            if (!isset($vlan['vlan_vlan'])) {
                continue;
            }
            try {
                $data = [
                    'device_id'   => $device->device_id,
                    'vlan_vlan'   => $vlan['vlan_vlan'],
                    'vlan_domain' => $vlan['vlan_domain'] ?? 1,
                    'vlan_name'   => $vlan['vlan_name'] ?? "VLAN {$vlan['vlan_vlan']}",
                    'vlan_type'   => $vlan['vlan_type'] ?? 'ethernet',
                    'vlan_mtu'    => $vlan['vlan_mtu'] ?? null,
                ];

                $existing = DB::table('vlans')
                    ->where('device_id', $device->device_id)
                    ->where('vlan_vlan', $data['vlan_vlan'])
                    ->first();

                if ($existing) {
                    DB::table('vlans')->where('vlan_id', $existing->vlan_id)->update($data);
                    $vlanId = $existing->vlan_id;
                } else {
                    $vlanId = DB::table('vlans')->insertGetId($data);
                }
                $trackedIds[] = $vlanId;

                // Handle port associations
                if (!empty($vlan['ports'])) {
                    $instance->syncVlanPorts($vlanId, $vlan['ports']);
                }
            } catch (\Throwable $e) {
                Log::warning("saveVlans failed: {$e->getMessage()}");
            }
        }
    }

    protected function syncVlanPorts(int $vlanId, array $ports): void
    {
        foreach ($ports as $portRef) {
            $port = DB::table('ports')
                ->where('device_id', $this->device->device_id)
                ->where(function ($q) use ($portRef) {
                    $q->where('ifIndex', $portRef['ifIndex'] ?? null)
                      ->orWhere('ifName', $portRef['ifName'] ?? $portRef['port_name'] ?? null);
                })
                ->first();

            if ($port) {
                DB::table('ports_vlans')->updateOrInsert(
                    ['port_id' => $port->port_id, 'vlan' => DB::table('vlans')->where('vlan_id', $vlanId)->value('vlan_vlan')],
                    [
                        'device_id' => $this->device->device_id,
                        'baseport' => $portRef['baseport'] ?? $port->ifIndex ?? 0,
                        'priority' => $portRef['priority'] ?? 0,
                        'state' => $portRef['state'] ?? 'unknown',
                        'cost' => $portRef['cost'] ?? 0,
                        'untagged' => $portRef['untagged'] ?? 0,
                    ]
                );
            }
        }
    }

    public static function savePortsStatistics(Device $device, array $statistics): void
    {
        $rrdDef = RrdDefinition::make()
            ->addDataset('INOCTETS', 'DERIVE', 0)
            ->addDataset('OUTOCTETS', 'DERIVE', 0)
            ->addDataset('INERRORS', 'DERIVE', 0)
            ->addDataset('OUTERRORS', 'DERIVE', 0)
            ->addDataset('INUCASTPKTS', 'DERIVE', 0)
            ->addDataset('OUTUCASTPKTS', 'DERIVE', 0);

        $currentTime = time();

        foreach ($statistics as $stat) {
            try {
                // Find port
                $port = DB::table('ports')
                    ->where('device_id', $device->device_id)
                    ->where(function ($q) use ($stat) {
                        $q->where('ifIndex', $stat['ifIndex'] ?? null)
                          ->orWhere('ifName', $stat['ifName'] ?? null);
                    })
                    ->first();

                if (!$port) {
                    continue;
                }

                // Calculate poll interval and rates
                $pollInterval = 300; // Default 5 minutes
                if ($port->poll_time && $port->poll_time > 0) {
                    $timeDiff = $currentTime - $port->poll_time;
                    if ($timeDiff > 0 && $timeDiff < 900) {
                        $pollInterval = $timeDiff;
                    }
                }

                // Calculate rates (bytes/sec) from counter differences
                $newInOctets = $stat['ifInOctets'] ?? $port->ifInOctets ?? 0;
                $newOutOctets = $stat['ifOutOctets'] ?? $port->ifOutOctets ?? 0;
                $newInErrors = $stat['ifInErrors'] ?? $port->ifInErrors ?? 0;
                $newOutErrors = $stat['ifOutErrors'] ?? $port->ifOutErrors ?? 0;
                $newInPkts = $stat['ifInUcastPkts'] ?? $port->ifInUcastPkts ?? 0;
                $newOutPkts = $stat['ifOutUcastPkts'] ?? $port->ifOutUcastPkts ?? 0;

                $oldInOctets = $port->ifInOctets ?? 0;
                $oldOutOctets = $port->ifOutOctets ?? 0;
                $oldInErrors = $port->ifInErrors ?? 0;
                $oldOutErrors = $port->ifOutErrors ?? 0;
                $oldInPkts = $port->ifInUcastPkts ?? 0;
                $oldOutPkts = $port->ifOutUcastPkts ?? 0;

                // Calculate rates - handle counter wraps/resets
                $inOctetsRate = 0;
                $outOctetsRate = 0;
                $inErrorsRate = 0;
                $outErrorsRate = 0;
                $inPktsRate = 0;
                $outPktsRate = 0;

                if ($pollInterval > 0 && $newInOctets >= $oldInOctets) {
                    $inOctetsRate = ($newInOctets - $oldInOctets) / $pollInterval;
                }
                if ($pollInterval > 0 && $newOutOctets >= $oldOutOctets) {
                    $outOctetsRate = ($newOutOctets - $oldOutOctets) / $pollInterval;
                }
                if ($pollInterval > 0 && $newInErrors >= $oldInErrors) {
                    $inErrorsRate = ($newInErrors - $oldInErrors) / $pollInterval;
                }
                if ($pollInterval > 0 && $newOutErrors >= $oldOutErrors) {
                    $outErrorsRate = ($newOutErrors - $oldOutErrors) / $pollInterval;
                }
                if ($pollInterval > 0 && $newInPkts >= $oldInPkts) {
                    $inPktsRate = ($newInPkts - $oldInPkts) / $pollInterval;
                }
                if ($pollInterval > 0 && $newOutPkts >= $oldOutPkts) {
                    $outPktsRate = ($newOutPkts - $oldOutPkts) / $pollInterval;
                }

                // Update port statistics, rates, and poll_time
                DB::table('ports')->where('port_id', $port->port_id)->update([
                    'ifInOctets'       => $newInOctets,
                    'ifOutOctets'      => $newOutOctets,
                    'ifInErrors'       => $newInErrors,
                    'ifOutErrors'      => $newOutErrors,
                    'ifInUcastPkts'    => $newInPkts,
                    'ifOutUcastPkts'   => $newOutPkts,
                    'ifInOctets_rate'  => $inOctetsRate,
                    'ifOutOctets_rate' => $outOctetsRate,
                    'ifInErrors_rate'  => $inErrorsRate,
                    'ifOutErrors_rate' => $outErrorsRate,
                    'ifInUcastPkts_rate'  => $inPktsRate,
                    'ifOutUcastPkts_rate' => $outPktsRate,
                    'poll_time'        => $currentTime,
                    'poll_period'      => $pollInterval,
                ]);

                // RRD update
                $tags = [
                    'ifName' => $port->ifName,
                    'rrd_name' => ['port', $port->ifIndex ?? $port->port_id],
                    'rrd_def' => $rrdDef,
                ];

                $fields = [
                    'INOCTETS'     => $newInOctets,
                    'OUTOCTETS'    => $newOutOctets,
                    'INERRORS'     => $newInErrors,
                    'OUTERRORS'    => $newOutErrors,
                    'INUCASTPKTS'  => $newInPkts,
                    'OUTUCASTPKTS' => $newOutPkts,
                ];

                app('Datastore')->put($device->toArray(), 'port', $tags, $fields);
            } catch (\Throwable $e) {
                Log::warning("savePortsStatistics failed: {$e->getMessage()}");
            }
        }
    }

    public static function saveVminfo(Device $device, array $vms): void
    {
        $instance = new self($device);
        $instance->syncRecords('vminfo', $vms, ['vmwVmVMID'], function ($vm) {
            return [
                'vmwVmVMID'      => $vm['vmwVmVMID'] ?? $vm['vm_id'] ?? '',
                'vmwVmDisplayName' => $vm['vmwVmDisplayName'] ?? $vm['name'] ?? '',
                'vmwVmGuestOS'   => $vm['vmwVmGuestOS'] ?? $vm['guest_os'] ?? '',
                'vmwVmMemSize'   => $vm['vmwVmMemSize'] ?? $vm['memory_mb'] ?? 0,
                'vmwVmCpus'      => $vm['vmwVmCpus'] ?? $vm['cpu_count'] ?? 0,
                'vmwVmState'     => $vm['vmwVmState'] ?? $vm['power_state'] ?? 'unknown',
            ];
        }, ['id_column' => 'id']);
    }

    public static function saveDeviceInfo(Device $device, array $deviceInfo): void
    {
        try {
            $updates = [];
            if (isset($deviceInfo['version'])) {
                $updates['version'] = $deviceInfo['version'];
            }
            if (isset($deviceInfo['hardware'])) {
                $updates['hardware'] = $deviceInfo['hardware'];
            }
            if (isset($deviceInfo['serial'])) {
                $updates['serial'] = $deviceInfo['serial'];
            }
            if (isset($deviceInfo['features'])) {
                $updates['features'] = $deviceInfo['features'];
            }
            if (isset($deviceInfo['sysName'])) {
                $updates['sysName'] = $deviceInfo['sysName'];
            }
            if (isset($deviceInfo['sysDescr'])) {
                $updates['sysDescr'] = $deviceInfo['sysDescr'];
            }

            if (!empty($updates)) {
                DB::table('devices')->where('device_id', $device->device_id)->update($updates);
                Log::debug("Updated device info for {$device->hostname}");
            }
        } catch (\Throwable $e) {
            Log::warning("saveDeviceInfo failed: {$e->getMessage()}");
        }
    }

    public static function saveHosts(Device $device, array $hosts): void
    {
        $instance = new self($device);
        $instance->syncRecords('hypervisor_hosts', $hosts, ['host_id'], function ($host) {
            return [
                'host_id'        => $host['host_id'] ?? $host['moref'] ?? '',
                'host_name'      => $host['host_name'] ?? $host['name'] ?? '',
                'host_cpu_count' => $host['host_cpu_count'] ?? $host['cpu_count'] ?? 0,
                'host_memory'    => $host['host_memory'] ?? $host['memory_bytes'] ?? 0,
                'host_status'    => $host['host_status'] ?? $host['connection_state'] ?? 'unknown',
                'host_device_id' => $host['host_device_id'] ?? null,
            ];
        }, ['id_column' => 'id']);
    }

    public static function saveClusters(Device $device, array $clusters): void
    {
        $instance = new self($device);
        $instance->syncRecords('vmware_clusters', $clusters, ['cluster_id'], function ($cluster) {
            return [
                'cluster_id'   => $cluster['cluster_id'] ?? $cluster['moref'] ?? '',
                'cluster_name' => $cluster['cluster_name'] ?? $cluster['name'] ?? '',
                'host_count'   => $cluster['host_count'] ?? 0,
                'vm_count'     => $cluster['vm_count'] ?? 0,
                'cpu_total'    => $cluster['cpu_total'] ?? 0,
                'memory_total' => $cluster['memory_total'] ?? 0,
            ];
        }, ['id_column' => 'id']);
    }

    public static function saveHypervisorHosts(Device $device, array $hosts): void
    {
        self::saveHosts($device, $hosts);
    }

    public static function saveAlerts(Device $device, array $alerts): void
    {
        $instance = new self($device);
        $instance->syncRecords('storage_array_alerts', $alerts, ['alert_id'], function ($alert) {
            return [
                'alert_id'    => $alert['alert_id'] ?? $alert['id'] ?? '',
                'alert_code'  => $alert['alert_code'] ?? $alert['code'] ?? '',
                'severity'    => $alert['severity'] ?? 'info',
                'component'   => $alert['component'] ?? '',
                'message'     => $alert['message'] ?? $alert['description'] ?? '',
                'opened'      => $alert['opened'] ?? $alert['created_at'] ?? now(),
                'state'       => $alert['state'] ?? 'open',
            ];
        }, ['id_column' => 'id']);
    }

    /**
     * Storage hosts (initiators) - No native LibreNMS table exists.
     * This data is logged but not persisted. Consider viewing via device API or alerts.
     */
    public static function saveStorageHosts(Device $device, array $hosts): void
    {
        // No native table for storage hosts/initiators
        // Data is available via the API client but not persisted to database
        if (!empty($hosts)) {
            Log::debug("Pure Storage hosts data available but not persisted (no native table)", [
                'device_id' => $device->device_id,
                'count' => count($hosts),
            ]);
        }
    }

    /**
     * Storage array drives - Maps to entPhysical table as inventory items.
     * Note: Drive data is also captured via the 'inventory' capability which
     * includes drives from /hardware endpoint. This method provides additional
     * drive-specific details.
     */
    public static function saveDrives(Device $device, array $drives): void
    {
        // Drives are already captured via inventory capability → entPhysical table
        // This method now just logs the availability of drive-specific data
        if (!empty($drives)) {
            Log::debug("Pure Storage drives data available (also captured via inventory)", [
                'device_id' => $device->device_id,
                'count' => count($drives),
            ]);
        }
    }

    /**
     * Storage host groups - No native LibreNMS table exists.
     * This data is logged but not persisted.
     */
    public static function saveHostGroups(Device $device, array $groups): void
    {
        // No native table for host groups
        if (!empty($groups)) {
            Log::debug("Pure Storage host groups data available but not persisted (no native table)", [
                'device_id' => $device->device_id,
                'count' => count($groups),
            ]);
        }
    }

    /**
     * Protection groups (replication) - No native LibreNMS table exists.
     * This data is logged but not persisted.
     */
    public static function saveProtectionGroups(Device $device, array $groups): void
    {
        // No native table for protection/replication groups
        if (!empty($groups)) {
            Log::debug("Pure Storage protection groups data available but not persisted (no native table)", [
                'device_id' => $device->device_id,
                'count' => count($groups),
            ]);
        }
    }

    /**
     * FC/iSCSI ports - Data is captured via 'transceivers' capability → ports_transceivers table.
     * This method logs availability but does not persist to avoid duplication.
     */
    public static function saveFcPorts(Device $device, array $ports): void
    {
        // FC ports are already captured via transceivers capability → ports_transceivers table
        if (!empty($ports)) {
            Log::debug("Pure Storage FC ports data available (also captured via transceivers)", [
                'device_id' => $device->device_id,
                'count' => count($ports),
            ]);
        }
    }

    /**
     * Host-volume connections (LUN mappings) - No native LibreNMS table exists.
     * This data is logged but not persisted.
     */
    public static function saveConnections(Device $device, array $connections): void
    {
        // No native table for host-volume connections/LUN mappings
        if (!empty($connections)) {
            Log::debug("Pure Storage connections data available but not persisted (no native table)", [
                'device_id' => $device->device_id,
                'count' => count($connections),
            ]);
        }
    }

    // Additional methods that were in the original file

    public static function saveHrDevice(Device $device, array $hrDevices): void
    {
        $instance = new self($device);
        $instance->syncRecords('hrDevice', $hrDevices, ['hrDeviceIndex'], function ($hr) {
            return [
                'hrDeviceIndex' => $hr['hrDeviceIndex'] ?? 0,
                'hrDeviceDescr' => $hr['hrDeviceDescr'] ?? '',
                'hrDeviceType'  => $hr['hrDeviceType'] ?? '',
                'hrDeviceErrors' => $hr['hrDeviceErrors'] ?? 0,
                'hrDeviceStatus' => $hr['hrDeviceStatus'] ?? 'unknown',
            ];
        }, ['id_column' => 'hrDevice_id']);
    }

    public static function saveHrSystem(Device $device, array $hrSystemData): void
    {
        try {
            $updates = [
                'hrSystemUptime'      => $hrSystemData['hrSystemUptime'] ?? null,
                'hrSystemNumUsers'    => $hrSystemData['hrSystemNumUsers'] ?? null,
                'hrSystemProcesses'   => $hrSystemData['hrSystemProcesses'] ?? null,
                'hrSystemMaxProcesses' => $hrSystemData['hrSystemMaxProcesses'] ?? null,
            ];

            $updates = array_filter($updates, fn($v) => $v !== null);

            if (!empty($updates)) {
                DB::table('devices')->where('device_id', $device->device_id)->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning("saveHrSystem failed: {$e->getMessage()}");
        }
    }

    public static function saveStorageArray(Device $device, array $arrays): void
    {
        $instance = new self($device);
        $instance->syncRecords('storage_arrays', $arrays, ['array_name'], function ($arr) {
            return [
                'array_name'     => $arr['array_name'] ?? $arr['name'] ?? '',
                'array_id'       => $arr['array_id'] ?? $arr['id'] ?? '',
                'model'          => $arr['model'] ?? '',
                'version'        => $arr['version'] ?? '',
                'capacity_total' => $arr['capacity_total'] ?? $arr['capacity'] ?? 0,
                'capacity_used'  => $arr['capacity_used'] ?? $arr['space_used'] ?? 0,
                'data_reduction' => $arr['data_reduction'] ?? 1.0,
            ];
        }, ['id_column' => 'id']);
    }

    public static function saveControllers(Device $device, array $controllers): void
    {
        $instance = new self($device);
        $instance->syncRecords('storage_controllers', $controllers, ['controller_name'], function ($ctrl) {
            return [
                'controller_name' => $ctrl['controller_name'] ?? $ctrl['name'] ?? '',
                'model'           => $ctrl['model'] ?? '',
                'serial'          => $ctrl['serial'] ?? '',
                'status'          => $ctrl['status'] ?? 'unknown',
                'mode'            => $ctrl['mode'] ?? '',
                'version'         => $ctrl['version'] ?? '',
            ];
        }, ['id_column' => 'id']);
    }

    public static function saveVolumes(Device $device, array $volumes): void
    {
        $instance = new self($device);
        $rrdDef = RrdDefinition::make()
            ->addDataset('used', 'GAUGE', 0)
            ->addDataset('provisioned', 'GAUGE', 0);

        $instance->syncRecords('storage_array_volumes', $volumes, ['volume_name'], function ($vol) {
            return [
                'volume_name' => $vol['volume_name'] ?? $vol['name'] ?? '',
                'volume_id'   => $vol['volume_id'] ?? $vol['id'] ?? '',
                'size'        => $vol['size'] ?? $vol['provisioned'] ?? 0,
                'used'        => $vol['used'] ?? $vol['space_used'] ?? 0,
                'data_reduction' => $vol['data_reduction'] ?? 1.0,
            ];
        }, [
            'id_column' => 'id',
            'rrd' => [
                'measurement' => 'storage_volume',
                'def' => $rrdDef,
                'fields' => ['used' => 'used', 'provisioned' => 'size'],
            ],
        ]);
    }

    public static function saveIpv4Mac(Device $device, array $mappings): void
    {
        foreach ($mappings as $map) {
            if (empty($map['ipv4_address']) || empty($map['mac_address'])) {
                continue;
            }
            try {
                DB::table('ipv4_mac')->updateOrInsert(
                    [
                        'device_id' => $device->device_id,
                        'ipv4_address' => $map['ipv4_address'],
                    ],
                    [
                        'mac_address' => $map['mac_address'],
                        'port_id' => $map['port_id'] ?? null,
                        'context_name' => $map['context_name'] ?? '',
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning("saveIpv4Mac failed: {$e->getMessage()}");
            }
        }
    }

    public static function saveIpv4Networks(Device $device, array $networks): void
    {
        foreach ($networks as $net) {
            if (empty($net['ipv4_network'])) {
                continue;
            }
            try {
                DB::table('ipv4_networks')->updateOrInsert(
                    ['ipv4_network' => $net['ipv4_network']],
                    ['context_name' => $net['context_name'] ?? '']
                );
            } catch (\Throwable $e) {
                Log::warning("saveIpv4Networks failed: {$e->getMessage()}");
            }
        }
    }
}
