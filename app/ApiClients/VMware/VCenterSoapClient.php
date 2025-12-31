<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;
use SoapClient;
use stdClass;
use RuntimeException;

/**
 * vCenter SOAP API Client
 * Handles complex property retrieval for VLANs (Trunks/PVLANs) and Real-time Performance Metrics.
 */
class VCenterSoapClient implements DeviceApiClientInterface
{
    protected ?SoapClient $client = null;
    protected ?string $sessionId = null;
    protected Device $device;
    protected array $credentials;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Read credentials from device attributes (decrypt if encrypted)
        $baseUrl = $device->getAttrib('api_base_url');
        $username = DeviceApiSettings::getCredential($device, 'api_credential_username');
        $password = DeviceApiSettings::getCredential($device, 'api_credential_password');

        if (!$baseUrl) {
            throw new RuntimeException("VCenterSoapClient: No API configuration found for device {$device->device_id}");
        }

        if (empty($username) || empty($password)) {
            throw new RuntimeException("VCenterSoapClient: No API credentials configured for device {$device->device_id}");
        }

        $this->credentials = ['username' => $username, 'password' => $password];

        // Ensure we hit the SDK endpoint
        if (str_contains($baseUrl, '/api')) {
            $baseUrl = preg_replace('#/api/?$#', '/sdk', $baseUrl);
        } elseif (!str_ends_with($baseUrl, '/sdk')) {
            $baseUrl = rtrim($baseUrl, '/') . '/sdk';
        }

        $this->initializeSoapClient($baseUrl);
    }

    protected function initializeSoapClient(string $endpoint): void
    {
        try {
            // Force WSDL location to avoid DNS/Firewall issues resolving the internal WSDL URL provided by vCenter
            $wsdl = "https://{$this->device->hostname}/sdk/vimService.wsdl";

            $this->client = new SoapClient($wsdl, [
                'location'           => $endpoint,
                'trace'              => true,
                'exceptions'         => true,
                'connection_timeout' => 20,
                'cache_wsdl'         => WSDL_CACHE_BOTH, // Cache WSDL for performance
                'stream_context'     => stream_context_create([
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                ]),
            ]);
        } catch (\Exception $e) {
            throw new RuntimeException("VCenterSoapClient: SOAP Init failed: {$e->getMessage()}");
        }
    }

    protected function login(): bool
    {
        if ($this->sessionId) return true;

        try {
            $sc = $this->getServiceContent();
            if (!$sc) return false;

            $response = $this->client->__soapCall('Login', [[
                '_this'    => $sc->sessionManager,
                'userName' => $this->credentials['username'],
                'password' => $this->credentials['password'],
            ]]);

            $this->sessionId = $response->returnval->key ?? null;
            return (bool)$this->sessionId;
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient: Login failed for {$this->device->hostname}: {$e->getMessage()}");
            return false;
        }
    }

    protected function logout(): void
    {
        if (!$this->sessionId) return;
        try {
            $sc = $this->getServiceContent();
            if ($sc) {
                $this->client->__soapCall('Logout', [['_this' => $sc->sessionManager]]);
            }
        } catch (\Exception $e) {
            // Ignore logout errors
        } finally {
            $this->sessionId = null;
        }
    }

    protected function getServiceContent(): ?object
    {
        try {
            $response = $this->client->__soapCall('RetrieveServiceContent', [
                ['_this' => ['_' => 'ServiceInstance', 'type' => 'ServiceInstance']],
            ]);
            return $response->returnval ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Main entry point to fetch VLANs
     * Merges Distributed Virtual Switch (DVS) logic with Standard Switch logic.
     */
    public function fetchVlans(Device $device): array
    {
        $vlans = [];
        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            // 1. Fetch DVS Portgroups (Trunks, PVLANs, Access)
            $dvsVlans = $this->fetchDistributedPortGroups($sc);

            // 2. Fetch Standard Portgroups (from host crawling)
            $stdVlans = $this->fetchStandardPortGroups($sc);

            // Merge results, deduplicating by name
            $vlans = $dvsVlans;
            $existingNames = array_column($dvsVlans, 'vlan_name');

            foreach ($stdVlans as $std) {
                if (!in_array($std['vlan_name'], $existingNames)) {
                    $vlans[] = $std;
                }
            }

            $this->logout();
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient fetchVlans error: " . $e->getMessage());
        }

        return $vlans;
    }

    /**
     * Logic ported from Python script to handle VlanIdSpec, TrunkVlanSpec, PvlanSpec
     * Matches: parse_dvs_vlan() and DVS section from Python
     */
    protected function fetchDistributedPortGroups($sc): array
    {
        $results = [];
        // Create container view for DistributedVirtualPortgroup
        $view = $this->createContainerView($sc, ['DistributedVirtualPortgroup']);
        if (!$view) return [];

        // Request 'config' and 'key' - SOAP doesn't support nested paths like 'config.defaultPortConfig.vlan'
        $properties = $this->retrieveProperties($sc, $view, 'DistributedVirtualPortgroup', [
            'name', 'key', 'config'
        ]);

        foreach ($properties as $pg) {
            $name = $pg['name'] ?? 'Unknown';
            $key = $pg['key'] ?? '';
            $config = $pg['config'] ?? null;

            if (!$config || !is_object($config)) continue;

            // Skip uplink port groups (matches Python: skip UplinkPortgroupConfig)
            if (isset($config->uplink) && $config->uplink === true) continue;

            // Navigate to defaultPortConfig->vlan
            $defaultPortConfig = $config->defaultPortConfig ?? null;
            if (!$defaultPortConfig || !is_object($defaultPortConfig)) continue;

            $vlanSpec = $defaultPortConfig->vlan ?? null;
            if (!$vlanSpec) continue;

            $vlanId = null;
            $type = 'DVS';  // Default for DVS port groups
            $suffix = '';

            // Handle Spec Types (matches Python parse_dvs_vlan logic)
            if (isset($vlanSpec->vlanId)) {
                $vlanIdValue = $vlanSpec->vlanId;

                if (is_numeric($vlanIdValue)) {
                    // VlanIdSpec: Single Access VLAN
                    $vlanId = (int)$vlanIdValue;
                    $type = 'DVS';
                } elseif (is_array($vlanIdValue) || is_object($vlanIdValue)) {
                    // TrunkVlanSpec: Trunk with ranges
                    $ranges = is_array($vlanIdValue) ? $vlanIdValue : [$vlanIdValue];
                    $strRanges = [];
                    foreach ($ranges as $r) {
                        if (is_object($r)) {
                            $start = $r->start ?? 0;
                            $end = $r->end ?? 0;
                            if ($start == $end) {
                                $strRanges[] = $start;
                            } else {
                                $strRanges[] = "{$start}-{$end}";
                            }
                        }
                    }
                    $suffix = ' [Trunk:' . implode(',', $strRanges) . ']';
                    $vlanId = null; // Trunk ports don't have a single VLAN ID
                    $type = 'trunk';
                }
            } elseif (isset($vlanSpec->pvlanId)) {
                // PvlanSpec: Private VLAN
                $pvlanId = is_object($vlanSpec->pvlanId)
                    ? (int)(get_object_vars($vlanSpec->pvlanId)[array_key_first(get_object_vars($vlanSpec->pvlanId))] ?? 0)
                    : (int)$vlanSpec->pvlanId;
                $suffix = " [PVLAN:{$pvlanId}]";
                $vlanId = $pvlanId;
                $type = 'pvlan';
            }

            // Use port group key for stable, unique domain (not CRC32 hash)
            $domain = 1;
            if ($key && preg_match('/(\d+)$/', $key, $m)) {
                $domain = (int)$m[1];
            } elseif ($key) {
                $domain = (crc32($key) & 0x7FFFFFFF) % 4096;
            }

            $results[] = [
                'vlan_vlan'   => $vlanId,
                'vlan_domain' => $domain,
                'vlan_name'   => $name . $suffix,
                'vlan_type'   => $type,
                'vlan_mtu'    => null,
            ];
        }

        $this->destroyView($view);
        return $results;
    }

    /**
     * Crawl hosts to find Standard Switch Portgroups
     * Note: Standard vSwitches are per-host, not cluster-wide like DVS
     */
    protected function fetchStandardPortGroups($sc): array
    {
        $results = [];
        $view = $this->createContainerView($sc, ['HostSystem']);
        if (!$view) return [];

        // Fetch host properties individually (SOAP doesn't support nested paths well)
        $hosts = $this->retrieveProperties($sc, $view, 'HostSystem', ['name', 'runtime', 'configManager']);

        foreach ($hosts as $host) {
            // Check connection state
            $runtime = $host['runtime'] ?? null;
            if (!$runtime || !is_object($runtime)) continue;
            if (($runtime->connectionState ?? '') !== 'connected') continue;

            // Get network system reference
            $configManager = $host['configManager'] ?? null;
            if (!$configManager || !is_object($configManager)) continue;
            $networkSystem = $configManager->networkSystem ?? null;
            if (!$networkSystem) continue;

            // Retrieve network info for this host's network system
            try {
                $netInfo = $this->retrievePropertiesBatch($sc, [$networkSystem], ['networkInfo']);
                if (empty($netInfo)) continue;

                $networkInfoObj = $netInfo[0]['networkInfo'] ?? null;
                if (!$networkInfoObj || !is_object($networkInfoObj)) continue;

                // Extract port groups from networkInfo
                $pgList = $networkInfoObj->portgroup ?? [];
                if (!is_array($pgList)) $pgList = [$pgList];

                foreach ($pgList as $pg) {
                    if (!is_object($pg) || !isset($pg->spec)) continue;

                    $spec = $pg->spec;
                    if (!isset($spec->name)) continue;

                    $name = $spec->name;
                    $vid = isset($spec->vlanId) ? (int)$spec->vlanId : 0;

                    // VLAN 4095 = "Trunk (All)" on standard switches
                    $suffix = '';
                    $type = 'standard';
                    if ($vid === 4095) {
                        $suffix = ' [Trunk (All)]';
                        $vid = null;
                        $type = 'trunk';
                    }

                    // Use original name for domain calculation
                    $domain = (crc32($name) & 0x7FFFFFFF) % 4096;

                    // Deduplicate by name (DVS takes precedence)
                    if (!isset($results[$name])) {
                        $results[$name] = [
                            'vlan_vlan'   => $vid,
                            'vlan_domain' => $domain,
                            'vlan_name'   => $name . $suffix,
                            'vlan_type'   => $type,
                            'vlan_mtu'    => null,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::debug("VCenterSoapClient: Failed to fetch standard port groups for host {$host['name']}: {$e->getMessage()}");
                continue;
            }
        }

        $this->destroyView($view);
        return array_values($results);
    }

    /**
     * Fetch cluster-level metrics (CPU/memory usage, host counts, VM counts)
     */
    public function fetchClusters(Device $device): array
    {
        $clusters = [];
        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            $view = $this->createContainerView($sc, ['ClusterComputeResource']);
            if (!$view) {
                $this->logout();
                return [];
            }

            // Note: 'vm' property cannot be retrieved via RetrieveProperties for ClusterComputeResource
            // VM counts are available in summary.numVmsTotal and summary.numVmsPoweredOn (if available)
            $clusterProps = $this->retrieveProperties($sc, $view, 'ClusterComputeResource', [
                'name', 'host', 'summary', 'configuration'
            ]);

            foreach ($clusterProps as $cluster) {
                $name = $cluster['name'] ?? 'Unknown';
                $summary = $cluster['summary'] ?? null;
                $hosts = $cluster['host'] ?? [];
                $config = $cluster['configuration'] ?? null;

                if (!$summary || !is_object($summary)) continue;

                // Extract metrics from summary
                $totalCpu = $summary->totalCpu ?? 0;  // MHz
                $totalMemory = $summary->totalMemory ?? 0;  // Bytes
                $effectiveCpu = $summary->effectiveCpu ?? 0;  // MHz
                $effectiveMemory = $summary->effectiveMemory ?? 0;  // MB

                // Get host counts from summary (should be directly available)
                $numHosts = $summary->numHosts ?? (is_array($hosts) ? count($hosts) : (is_object($hosts) ? 1 : 0));
                $numEffectiveHosts = $summary->numEffectiveHosts ?? $numHosts;

                // VM counts from usageSummary (available in all tested vCenter versions)
                $numVmsTotal = 0;
                $numVmsPoweredOn = 0;

                if (isset($summary->usageSummary)) {
                    $numVmsTotal = $summary->usageSummary->totalVmCount ?? 0;
                    $poweredOffVmCount = $summary->usageSummary->poweredOffVmCount ?? 0;

                    // vCenter provides poweredOffVmCount, so we calculate powered on VMs
                    $numVmsPoweredOn = $numVmsTotal - $poweredOffVmCount;
                }

                // CPU and Memory usage percentages
                $cpuUsagePct = 0;
                $memUsagePct = 0;
                if ($totalCpu > 0) {
                    $usedCpu = $totalCpu - $effectiveCpu;
                    $cpuUsagePct = ($usedCpu / $totalCpu) * 100;
                }
                if ($totalMemory > 0) {
                    $usedMemory = ($totalMemory / (1024 * 1024)) - $effectiveMemory;  // Convert to MB
                    $memUsagePct = ($usedMemory / ($totalMemory / (1024 * 1024))) * 100;
                }

                $clusters[] = [
                    'cluster_name' => $name,
                    'num_hosts' => $numHosts,
                    'num_effective_hosts' => $numEffectiveHosts,
                    'num_vms_total' => $numVmsTotal,
                    'num_vms_powered_on' => $numVmsPoweredOn,
                    'total_cpu_mhz' => $totalCpu,
                    'effective_cpu_mhz' => $effectiveCpu,
                    'cpu_usage_pct' => round($cpuUsagePct, 2),
                    'total_memory_mb' => round($totalMemory / (1024 * 1024), 2),
                    'effective_memory_mb' => $effectiveMemory,
                    'memory_usage_pct' => round($memUsagePct, 2),
                ];
            }

            $this->destroyView($view);
            $this->logout();
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient fetchClusters error: " . $e->getMessage());
        }

        return $clusters;
    }

    /**
     * Fetch VMs with snapshots for monitoring
     */
    public function fetchVMSnapshots(Device $device): array
    {
        $vmSnapshots = [];
        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            $view = $this->createContainerView($sc, ['VirtualMachine']);
            if (!$view) {
                $this->logout();
                return [];
            }

            $vmProps = $this->retrieveProperties($sc, $view, 'VirtualMachine', [
                'name', 'snapshot', 'runtime'
            ]);

            foreach ($vmProps as $vm) {
                $name = $vm['name'] ?? 'Unknown';
                $snapshot = $vm['snapshot'] ?? null;

                // Only include VMs that have snapshots
                if (!$snapshot || !is_object($snapshot)) continue;

                $runtime = $vm['runtime'] ?? null;
                $powerState = $runtime && is_object($runtime) ? ($runtime->powerState ?? 'unknown') : 'unknown';

                // Count snapshots
                $snapshotCount = 0;
                $snapshotList = [];

                if (isset($snapshot->rootSnapshotList)) {
                    $rootSnapshots = is_array($snapshot->rootSnapshotList)
                        ? $snapshot->rootSnapshotList
                        : [$snapshot->rootSnapshotList];

                    foreach ($rootSnapshots as $snap) {
                        $snapshotCount += $this->countSnapshots($snap, $snapshotList);
                    }
                }

                if ($snapshotCount > 0) {
                    $vmSnapshots[] = [
                        'vm_name' => $name,
                        'power_state' => $powerState,
                        'snapshot_count' => $snapshotCount,
                        'snapshots' => $snapshotList,
                    ];
                }
            }

            $this->destroyView($view);
            $this->logout();
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient fetchVMSnapshots error: " . $e->getMessage());
        }

        return $vmSnapshots;
    }

    /**
     * Recursively count snapshots in snapshot tree
     */
    private function countSnapshots($snapshot, &$snapshotList): int
    {
        $count = 1;

        if (is_object($snapshot)) {
            $name = $snapshot->name ?? 'Unknown';
            $createTime = $snapshot->createTime ?? null;
            $snapshotList[] = [
                'name' => $name,
                'create_time' => $createTime,
            ];

            if (isset($snapshot->childSnapshotList)) {
                $children = is_array($snapshot->childSnapshotList)
                    ? $snapshot->childSnapshotList
                    : [$snapshot->childSnapshotList];

                foreach ($children as $child) {
                    $count += $this->countSnapshots($child, $snapshotList);
                }
            }
        }

        return $count;
    }

    // --- SOAP Helper Methods ---

    protected function createContainerView($sc, array $types)
    {
        $response = $this->client->__soapCall('CreateContainerView', [[
            '_this' => $sc->viewManager,
            'container' => $sc->rootFolder,
            'type' => $types,
            'recursive' => true
        ]]);
        return $response->returnval ?? null;
    }

    protected function destroyView($view)
    {
        if ($view) $this->client->__soapCall('DestroyView', [['_this' => $view]]);
    }

    /**
     * Efficiently retrieve specific properties using PropertyCollector
     * Uses direct container view query to avoid traversal spec issues
     */
    protected function retrieveProperties($sc, $view, $type, array $pathSet): array
    {
        // First, get the list of MoRefs from the container view
        $moRefs = $this->getContainerViewContents($sc, $view);

        if (empty($moRefs)) {
            return [];
        }

        // Now retrieve properties for all those objects in batches
        return $this->retrievePropertiesBatch($sc, $moRefs, $pathSet, $type);
    }

    /**
     * Get MoRefs from a container view
     */
    protected function getContainerViewContents($sc, $view): array
    {
        try {
            $propSpec = new \stdClass();
            $propSpec->type = 'ContainerView';
            $propSpec->all = false;
            $propSpec->pathSet = ['view'];

            $objSpec = new \stdClass();
            $objSpec->obj = $view;
            $objSpec->skip = false;

            $spec = new \stdClass();
            $spec->propSet = [$propSpec];
            $spec->objectSet = [$objSpec];

            $request = [
                '_this' => $sc->propertyCollector,
                'specSet' => [$spec],
            ];

            $response = $this->client->__soapCall('RetrieveProperties', [$request]);

            $result = $response->returnval ?? $response;
            $propSet = is_array($result->propSet ?? null) ? $result->propSet : [$result->propSet ?? new \stdClass()];

            foreach ($propSet as $prop) {
                if ($prop->name === 'view' && isset($prop->val)) {
                    $viewContents = $prop->val;
                    // MoRefs are wrapped in ManagedObjectReference property
                    if (is_object($viewContents) && isset($viewContents->ManagedObjectReference)) {
                        $moRefs = $viewContents->ManagedObjectReference;
                        return is_array($moRefs) ? $moRefs : [$moRefs];
                    }
                    // Fallback for direct array/object
                    if (is_array($viewContents)) {
                        return $viewContents;
                    } elseif (is_object($viewContents)) {
                        return [$viewContents];
                    }
                }
            }

            return [];
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: getContainerViewContents failed: {$e->getMessage()}");
            return [];
        }
    }

    protected function retrievePropertiesBatch($sc, array $moRefs, array $pathSet, string $type = null): array
    {
        if (empty($moRefs)) return [];

        // Auto-detect type from first MoRef if not specified
        if (!$type && !empty($moRefs) && is_object($moRefs[0])) {
            $type = $moRefs[0]->type ?? 'ManagedEntity';
        }

        $propSpec = new stdClass();
        $propSpec->type = $type ?? 'ManagedEntity';
        $propSpec->pathSet = $pathSet;
        $propSpec->all = false;

        $objSpecs = [];
        foreach ($moRefs as $moRef) {
            $os = new stdClass();
            $os->obj = $moRef;
            $os->skip = false;
            $objSpecs[] = $os;
        }

        // Chunking to avoid massive SOAP envelopes
        $chunks = array_chunk($objSpecs, 50);
        $results = [];

        foreach ($chunks as $chunk) {
            $filterSpec = new stdClass();
            $filterSpec->objectSet = $chunk;
            $filterSpec->propSet   = [$propSpec];
            $results = array_merge($results, $this->executeRetrieveProperties($sc, [$filterSpec]));
        }

        return $results;
    }

    protected function executeRetrieveProperties($sc, array $specSet): array
    {
        try {
            $res = $this->client->__soapCall('RetrieveProperties', [[
                '_this' => $sc->propertyCollector,
                'specSet' => $specSet
            ]]);
        } catch (\SoapFault $e) {
            Log::error("VCenterSoapClient SOAP Fault during property retrieval", [
                'faultcode' => $e->faultcode ?? 'N/A',
                'faultstring' => $e->faultstring ?? 'N/A',
                'message' => $e->getMessage(),
                'detail' => isset($e->detail) ? json_encode($e->detail) : 'N/A',
            ]);
            return [];
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient property retrieval error: " . $e->getMessage(), [
                'exception' => get_class($e),
            ]);
            return [];
        }

        $ret = [];
        $objects = $res->returnval ?? [];
        if (!is_array($objects)) $objects = [$objects];

        foreach ($objects as $objContent) {
            $item = [];
            // Include the MoRef in the result
            if (isset($objContent->obj)) {
                $item['moRef'] = $objContent->obj;
            }
            if (isset($objContent->propSet)) {
                $props = is_array($objContent->propSet) ? $objContent->propSet : [$objContent->propSet];
                foreach ($props as $p) {
                    $item[$p->name] = $p->val;
                }
            }
            $ret[] = $item;
        }
        return $ret;
    }

    // --- Performance Metrics Collection ---

    /**
     * Fetch realtime performance metrics for hosts/VMs
     * Matches Python script requirements for CPU, Memory, Storage, Network
     */
    public function fetchHostRealTimePerformance(Device $device, array $entities = []): array
    {
        $metrics = [];
        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            // If no specific entities provided, get all connected hosts
            if (empty($entities)) {
                $view = $this->createContainerView($sc, ['HostSystem']);
                if (!$view) {
                    $this->logout();
                    return [];
                }
                $entities = $this->getContainerViewContents($sc, $view);
                $this->destroyView($view);
            }

            $perfManager = $sc->perfManager;

            // Define realtime counters to collect (20-second intervals)
            $counterKeys = $this->getPerformanceCounterKeys($perfManager, [
                'cpu.usage.average',         // CPU usage %
                'cpu.ready.summation',       // CPU ready time
                'mem.usage.average',         // Memory usage %
                'mem.consumed.average',      // Memory consumed MB
                'net.usage.average',         // Network usage KBps
                'net.bytesRx.average',       // Network RX
                'net.bytesTx.average',       // Network TX
                'disk.usage.average',        // Disk usage %
                'disk.read.average',         // Disk read KBps
                'disk.write.average',        // Disk write KBps
            ]);

            foreach ($entities as $entity) {
                if (!is_object($entity) || !isset($entity->_)) continue;

                $entityMetrics = $this->queryRealtimePerformance($perfManager, $entity, $counterKeys);
                if (!empty($entityMetrics)) {
                    $metrics[$entity->_] = $entityMetrics;
                }
            }

            $this->logout();
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient fetchHostRealTimePerformance error: " . $e->getMessage());
        }

        return $metrics;
    }

    /**
     * Get performance counter keys by metric name
     */
    protected function getPerformanceCounterKeys($perfManager, array $metricNames): array
    {
        try {
            $request = ['_this' => $perfManager];
            $response = $this->client->__soapCall('RetrieveAllCounter', [$request]);

            $counters = [];
            $allCounters = $response->returnval ?? [];
            if (!is_array($allCounters)) {
                $allCounters = [$allCounters];
            }

            foreach ($allCounters as $counter) {
                $groupName = $counter->groupInfo->key ?? '';
                $counterName = $counter->nameInfo->key ?? '';
                $rollupType = $counter->rollupType ?? '';
                $key = $counter->key ?? 0;

                $fullName = "{$groupName}.{$counterName}.{$rollupType}";

                if (in_array($fullName, $metricNames)) {
                    $counters[$fullName] = $key;
                }
            }

            return $counters;
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: getPerformanceCounterKeys failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Query realtime performance stats (20-second interval)
     */
    protected function queryRealtimePerformance($perfManager, $entity, array $counterKeys): array
    {
        try {
            // Build PerfQuerySpec
            $querySpec = new \stdClass();
            $querySpec->entity = $entity;
            $querySpec->maxSample = 1; // Get latest sample
            $querySpec->intervalId = 20; // Realtime = 20 seconds

            // Add metric IDs
            $metricIds = [];
            foreach ($counterKeys as $key) {
                $metricId = new \stdClass();
                $metricId->counterId = $key;
                $metricId->instance = ''; // Aggregate across all instances
                $metricIds[] = $metricId;
            }
            $querySpec->metricId = $metricIds;

            $request = [
                '_this' => $perfManager,
                'querySpec' => [$querySpec],
            ];

            $response = $this->client->__soapCall('QueryPerf', [$request]);

            $results = [];
            $perfEntityMetrics = $response->returnval ?? [];
            if (!is_array($perfEntityMetrics)) {
                $perfEntityMetrics = [$perfEntityMetrics];
            }

            foreach ($perfEntityMetrics as $entityMetric) {
                $values = $entityMetric->value ?? [];
                if (!is_array($values)) {
                    $values = [$values];
                }

                foreach ($values as $perfMetricSeries) {
                    $counterId = $perfMetricSeries->id->counterId ?? 0;
                    $instance = $perfMetricSeries->id->instance ?? '';
                    $samples = $perfMetricSeries->value ?? [];

                    if (!is_array($samples)) {
                        $samples = [$samples];
                    }

                    // Get latest value
                    $value = end($samples);

                    // Map counter ID back to metric name
                    $metricName = array_search($counterId, $counterKeys) ?: "counter_{$counterId}";

                    $results[$metricName] = [
                        'value' => $value,
                        'instance' => $instance,
                    ];
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: queryRealtimePerformance failed for {$entity->_}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Fetch historical performance metrics (daily averages)
     */
    public function fetchHostHistoricalPerformance(Device $device, array $entities = [], int $days = 7): array
    {
        // Similar to realtime but with intervalId = 86400 (daily) or custom range
        // Implementation follows same pattern as queryRealtimePerformance
        // Left for future enhancement based on specific requirements
        return [];
    }

    /**
     * Fetch vCenter Appliance network interfaces
     * Discovers the vCenter VM's network adapters and their configuration
     */
    public function fetchPorts(Device $device): array
    {
        $ports = [];
        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            // Find the vCenter Server VM itself
            $vcenterVm = $this->findVCenterVM($sc);
            if (!$vcenterVm) {
                Log::debug("VCenterSoapClient: Could not find vCenter VM for {$device->hostname}");
                $this->logout();
                return [];
            }

            // Get VM network adapter details
            $view = $this->createContainerView($sc, ['VirtualMachine']);
            if (!$view) {
                $this->logout();
                return [];
            }

            // Query VM properties for network configuration
            $vmProps = $this->retrievePropertiesBatch($sc, [$vcenterVm], [
                'name', 'guest', 'config'
            ]);

            if (empty($vmProps)) {
                $this->destroyView($view);
                $this->logout();
                return [];
            }

            $vmData = $vmProps[0];
            $guest = $vmData['guest'] ?? null;
            $config = $vmData['config'] ?? null;

            // Extract network adapters from guest tools (provides IP and MAC)
            $guestNics = [];
            if ($guest && is_object($guest) && isset($guest->net)) {
                $guestNetList = is_array($guest->net) ? $guest->net : [$guest->net];
                foreach ($guestNetList as $guestNet) {
                    if (!is_object($guestNet)) continue;

                    $deviceConfigId = $guestNet->deviceConfigId ?? null;
                    $guestNics[$deviceConfigId] = [
                        'mac' => $guestNet->macAddress ?? '',
                        'connected' => $guestNet->connected ?? false,
                        'ipConfig' => $guestNet->ipConfig ?? null,
                    ];
                }
            }

            // Extract hardware network adapters from config
            if ($config && is_object($config) && isset($config->hardware)) {
                $hardware = $config->hardware;
                $devices = isset($hardware->device) ? (is_array($hardware->device) ? $hardware->device : [$hardware->device]) : [];

                $ifIndex = 0;
                foreach ($devices as $device) {
                    if (!is_object($device)) continue;

                    // Check if this is a network adapter
                    $deviceClass = get_class($device);
                    if (!str_contains($deviceClass, 'VirtualEthernet')) continue;

                    $ifIndex++;
                    $label = $device->deviceInfo->label ?? "Network adapter $ifIndex";
                    $key = $device->key ?? $ifIndex;

                    // Get backing info for network name
                    $networkName = 'Unknown';
                    if (isset($device->backing)) {
                        $backing = $device->backing;
                        if (isset($backing->deviceName)) {
                            $networkName = $backing->deviceName;
                        } elseif (isset($backing->network)) {
                            // For DVS, we might need to resolve the network moref
                            $networkName = "DVS Port Group";
                        }
                    }

                    // Get connection state
                    $connectable = $device->connectable ?? null;
                    $connected = false;
                    $startConnected = true;
                    if ($connectable && is_object($connectable)) {
                        $connected = $connectable->connected ?? false;
                        $startConnected = $connectable->startConnected ?? true;
                    }

                    // Get MAC and IP from guest tools data
                    $guestData = $guestNics[$key] ?? [];
                    $macAddress = $guestData['mac'] ?? ($device->macAddress ?? '');
                    $connected = $guestData['connected'] ?? $connected;

                    $ports[] = [
                        'ifIndex' => $ifIndex,
                        'ifName' => $label,
                        'ifDescr' => "vCenter $label (Network: $networkName)",
                        'ifType' => 'ethernetCsmacd',
                        'ifSpeed' => 1000000000, // 1 Gbps default for VM NICs
                        'ifPhysAddress' => $macAddress,
                        'ifOperStatus' => $connected ? 'up' : 'down',
                        'ifAdminStatus' => $startConnected ? 'up' : 'down',
                        'ifMtu' => 1500,
                        '_key' => $key, // Store key for statistics lookup
                    ];
                }
            }

            $this->destroyView($view);
            $this->logout();
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient fetchPorts error: " . $e->getMessage());
        }

        return $ports;
    }

    /**
     * Fetch vCenter Appliance network traffic statistics
     * Uses PerformanceManager to get network throughput for vCenter VM
     */
    public function fetchPortsStatistics(Device $device): array
    {
        $statistics = [];
        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            // Find the vCenter Server VM
            $vcenterVm = $this->findVCenterVM($sc);
            if (!$vcenterVm) {
                Log::debug("VCenterSoapClient: Could not find vCenter VM for statistics");
                $this->logout();
                return [];
            }

            $perfManager = $sc->perfManager;

            // Get network performance counters
            $counterKeys = $this->getPerformanceCounterKeys($perfManager, [
                'net.bytesRx.average',       // Network RX bytes/sec
                'net.bytesTx.average',       // Network TX bytes/sec
                'net.packetsRx.summation',   // Network RX packets
                'net.packetsTx.summation',   // Network TX packets
                'net.droppedRx.summation',   // Network RX drops
                'net.droppedTx.summation',   // Network TX drops
            ]);

            if (empty($counterKeys)) {
                Log::debug("VCenterSoapClient: No network performance counters found");
                $this->logout();
                return [];
            }

            // Query performance for VM with per-instance metrics
            $perfData = $this->queryRealtimePerformancePerInstance($perfManager, $vcenterVm, $counterKeys);

            // Convert performance data to port statistics format
            // Group by network instance (e.g., "4000" for vmnic with key 4000)
            $instanceStats = [];
            foreach ($perfData as $metricName => $instances) {
                foreach ($instances as $instance => $value) {
                    if (!isset($instanceStats[$instance])) {
                        $instanceStats[$instance] = [
                            'ifIndex' => 0, // Will be mapped later
                            'instance' => $instance,
                        ];
                    }

                    // Map metric names to statistics fields
                    if (str_contains($metricName, 'bytesRx')) {
                        $instanceStats[$instance]['ifInOctets_rate'] = $value * 1024; // Convert KBps to Bps
                    } elseif (str_contains($metricName, 'bytesTx')) {
                        $instanceStats[$instance]['ifOutOctets_rate'] = $value * 1024;
                    } elseif (str_contains($metricName, 'packetsRx')) {
                        $instanceStats[$instance]['ifInUcastPkts_rate'] = $value;
                    } elseif (str_contains($metricName, 'packetsTx')) {
                        $instanceStats[$instance]['ifOutUcastPkts_rate'] = $value;
                    } elseif (str_contains($metricName, 'droppedRx')) {
                        $instanceStats[$instance]['ifInErrors_rate'] = $value;
                    } elseif (str_contains($metricName, 'droppedTx')) {
                        $instanceStats[$instance]['ifOutErrors_rate'] = $value;
                    }
                }
            }

            // Convert to array and assign ifIndex
            $ifIndex = 1;
            foreach ($instanceStats as $instance => $stats) {
                $statistics[] = array_merge([
                    'ifIndex' => $ifIndex++,
                    'ifInOctets_rate' => 0,
                    'ifOutOctets_rate' => 0,
                    'ifInUcastPkts_rate' => 0,
                    'ifOutUcastPkts_rate' => 0,
                    'ifInErrors_rate' => 0,
                    'ifOutErrors_rate' => 0,
                ], $stats);
            }

            $this->logout();
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient fetchPortsStatistics error: " . $e->getMessage());
        }

        return $statistics;
    }

    /**
     * Find the vCenter Server VM itself
     * Attempts multiple strategies to identify the vCenter appliance
     */
    protected function findVCenterVM($sc): ?object
    {
        try {
            // Strategy 1: Look for VM with name matching the vCenter hostname
            $hostname = $this->device->hostname;
            $hostnameParts = explode('.', $hostname);
            $shortHostname = $hostnameParts[0];

            $view = $this->createContainerView($sc, ['VirtualMachine']);
            if (!$view) return null;

            // Get all MoRefs first, before destroying the view
            $moRefs = $this->getContainerViewContents($sc, $view);

            // Now get properties for all VMs
            $vms = $this->retrievePropertiesBatch($sc, $moRefs, ['name', 'runtime'], 'VirtualMachine');

            // Strategy 1: Match by exact hostname or short hostname
            foreach ($vms as $vm) {
                $vmName = $vm['name'] ?? '';
                if (strcasecmp($vmName, $hostname) === 0 || strcasecmp($vmName, $shortHostname) === 0) {
                    // Found a match - return the corresponding MoRef
                    if (isset($vm['moRef'])) {
                        $this->destroyView($view);
                        return $vm['moRef'];
                    }
                }
            }

            // Strategy 2: Look for VM running vCenter service
            // Check for VMs with "vcenter" in the name
            foreach ($vms as $vm) {
                $vmName = strtolower($vm['name'] ?? '');
                if (str_contains($vmName, 'vcenter') || str_contains($vmName, 'vcsa')) {
                    // Found a match - return the corresponding MoRef
                    if (isset($vm['moRef'])) {
                        $this->destroyView($view);
                        return $vm['moRef'];
                    }
                }
            }

            $this->destroyView($view);
            return null;
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: findVCenterVM failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Query realtime performance with per-instance breakdown
     * Returns metrics grouped by instance (network adapter key)
     */
    protected function queryRealtimePerformancePerInstance($perfManager, $entity, array $counterKeys): array
    {
        try {
            $querySpec = new \stdClass();
            $querySpec->entity = $entity;
            $querySpec->maxSample = 1;
            $querySpec->intervalId = 20; // Realtime = 20 seconds

            // Add metric IDs - request all instances with '*'
            $metricIds = [];
            foreach ($counterKeys as $key) {
                $metricId = new \stdClass();
                $metricId->counterId = $key;
                $metricId->instance = '*'; // Get all instances
                $metricIds[] = $metricId;
            }
            $querySpec->metricId = $metricIds;

            $request = [
                '_this' => $perfManager,
                'querySpec' => [$querySpec],
            ];

            $response = $this->client->__soapCall('QueryPerf', [$request]);

            $results = [];
            $perfEntityMetrics = $response->returnval ?? [];
            if (!is_array($perfEntityMetrics)) {
                $perfEntityMetrics = [$perfEntityMetrics];
            }

            foreach ($perfEntityMetrics as $entityMetric) {
                $values = $entityMetric->value ?? [];
                if (!is_array($values)) {
                    $values = [$values];
                }

                foreach ($values as $perfMetricSeries) {
                    $counterId = $perfMetricSeries->id->counterId ?? 0;
                    $instance = $perfMetricSeries->id->instance ?? '';
                    $samples = $perfMetricSeries->value ?? [];

                    if (!is_array($samples)) {
                        $samples = [$samples];
                    }

                    $value = end($samples);

                    // Map counter ID back to metric name
                    $metricName = array_search($counterId, $counterKeys) ?: "counter_{$counterId}";

                    if (!isset($results[$metricName])) {
                        $results[$metricName] = [];
                    }

                    $results[$metricName][$instance] = $value;
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: queryRealtimePerformancePerInstance failed: {$e->getMessage()}");
            return [];
        }
    }

    // --- DeviceApiClientInterface implementations ---

    public function supports(Device $device): bool
    {
        $templateKey = $device->getAttrib('api_template_key');
        return in_array($templateKey, ['vmware_vcenter', 'vmware_vcenter_soap', 'vcenter_soap']);
    }

    public function testConnection(): array
    {
        return ['success' => (bool) $this->login()];
    }

    public function get(string $e, array $p = []): array
    {
        return [];
    }

    public function post(string $e, array $d = []): array
    {
        return [];
    }

    public function capabilities(): array
    {
        return ['vlans', 'sensors', 'ports', 'ports_stats', 'inventory', 'processors', 'mempools', 'storage', 'vms', 'clusters'];
    }

    /**
     * Fetch sensors from vCenter
     */
    public function fetchSensors(Device $device): array
    {
        $sensors = [];

        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            // Get cluster-level metrics as sensors
            $clusters = $this->fetchClusters($this->device);
            foreach ($clusters as $idx => $cluster) {
                $clusterName = $cluster['cluster_name'] ?? "Cluster-{$idx}";

                // CPU usage sensor
                if (isset($cluster['cpu_usage_pct'])) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'vcenter-cluster',
                        'sensor_descr' => "{$clusterName} CPU Usage",
                        'sensor_index' => "cluster-{$idx}-cpu",
                        'sensor_current' => $cluster['cpu_usage_pct'],
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Memory usage sensor
                if (isset($cluster['memory_usage_pct'])) {
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'vcenter-cluster',
                        'sensor_descr' => "{$clusterName} Memory Usage",
                        'sensor_index' => "cluster-{$idx}-mem",
                        'sensor_current' => $cluster['memory_usage_pct'],
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }

                // Host count sensor
                if (isset($cluster['num_hosts'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'vcenter-cluster',
                        'sensor_descr' => "{$clusterName} Host Count",
                        'sensor_index' => "cluster-{$idx}-hosts",
                        'sensor_current' => $cluster['num_hosts'],
                    ];
                }

                // VM count sensor
                if (isset($cluster['num_vms_total'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'vcenter-cluster',
                        'sensor_descr' => "{$clusterName} VM Count",
                        'sensor_index' => "cluster-{$idx}-vms",
                        'sensor_current' => $cluster['num_vms_total'],
                    ];
                }

                // Powered on VMs
                if (isset($cluster['num_vms_powered_on'])) {
                    $sensors[] = [
                        'sensor_class' => 'count',
                        'sensor_type' => 'vcenter-cluster',
                        'sensor_descr' => "{$clusterName} Powered On VMs",
                        'sensor_index' => "cluster-{$idx}-vms-on",
                        'sensor_current' => $cluster['num_vms_powered_on'],
                    ];
                }
            }

            // Get datastore capacity sensors
            $datastores = $this->fetchDatastores();
            foreach ($datastores as $idx => $ds) {
                $dsName = $ds['name'] ?? "Datastore-{$idx}";
                $freeSpace = $ds['freeSpace'] ?? 0;
                $capacity = $ds['capacity'] ?? 0;

                if ($capacity > 0) {
                    $usedPct = round((($capacity - $freeSpace) / $capacity) * 100, 2);
                    $sensors[] = [
                        'sensor_class' => 'percent',
                        'sensor_type' => 'vcenter-datastore',
                        'sensor_descr' => "{$dsName} Usage",
                        'sensor_index' => "ds-{$idx}-usage",
                        'sensor_current' => $usedPct,
                        'sensor_limit' => 90,
                        'sensor_limit_low' => 0,
                    ];
                }
            }

            $this->logout();

            Log::debug('VCenter SOAP: Fetched sensors', [
                'device_id' => $this->device->device_id,
                'count' => count($sensors),
            ]);

        } catch (\Throwable $e) {
            Log::error('VCenter SOAP fetchSensors failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $sensors;
    }

    /**
     * Fetch IPv4 addresses from vCenter appliance
     */
    public function fetchIpv4Addresses(Device $device): array
    {
        // The vCenter appliance IP is typically the device's primary IP
        // Additional IPs can be discovered from the vCenter VM guest tools
        return [];
    }

    /**
     * Fetch inventory from vCenter
     */
    public function fetchInventory(Device $device): array
    {
        $inventory = [];

        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            $entIndex = 1;

            // vCenter Appliance itself
            $inventory[] = [
                'entPhysicalIndex' => $entIndex++,
                'entPhysicalDescr' => 'VMware vCenter Server Appliance',
                'entPhysicalClass' => 'chassis',
                'entPhysicalName' => $this->device->hostname,
                'entPhysicalContainedIn' => 0,
                'entPhysicalMfgName' => 'VMware',
            ];

            // Get clusters as inventory items
            $clusters = $this->fetchClusters($this->device);
            foreach ($clusters as $cluster) {
                $inventory[] = [
                    'entPhysicalIndex' => $entIndex++,
                    'entPhysicalDescr' => 'vSphere Cluster',
                    'entPhysicalClass' => 'module',
                    'entPhysicalName' => $cluster['cluster_name'] ?? 'Unknown Cluster',
                    'entPhysicalContainedIn' => 1,
                    'entPhysicalMfgName' => 'VMware',
                ];
            }

            // Get hosts as inventory items
            $hosts = $this->fetchHosts();
            foreach ($hosts as $host) {
                $inventory[] = [
                    'entPhysicalIndex' => $entIndex++,
                    'entPhysicalDescr' => 'ESXi Host',
                    'entPhysicalClass' => 'module',
                    'entPhysicalName' => $host['name'] ?? 'Unknown Host',
                    'entPhysicalModelName' => $host['model'] ?? '',
                    'entPhysicalSerialNum' => $host['serial'] ?? '',
                    'entPhysicalContainedIn' => 1,
                    'entPhysicalMfgName' => $host['vendor'] ?? 'Unknown',
                    'entPhysicalSoftwareRev' => $host['version'] ?? '',
                ];
            }

            $this->logout();

            Log::debug('VCenter SOAP: Fetched inventory', [
                'device_id' => $this->device->device_id,
                'count' => count($inventory),
            ]);

        } catch (\Throwable $e) {
            Log::error('VCenter SOAP fetchInventory failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $inventory;
    }

    /**
     * Fetch processors (cluster-level CPU aggregation)
     */
    public function fetchProcessors(Device $device): array
    {
        $processors = [];

        try {
            $clusters = $this->fetchClusters($this->device);
            foreach ($clusters as $idx => $cluster) {
                $processors[] = [
                    'processor_index' => $idx,
                    'processor_type' => 'vcenter-cluster',
                    'processor_descr' => ($cluster['cluster_name'] ?? "Cluster {$idx}") . ' Aggregate CPU',
                    'processor_usage' => $cluster['cpu_usage_pct'] ?? null,
                ];
            }

            Log::debug('VCenter SOAP: Fetched processors', [
                'device_id' => $this->device->device_id,
                'count' => count($processors),
            ]);

        } catch (\Throwable $e) {
            Log::error('VCenter SOAP fetchProcessors failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $processors;
    }

    /**
     * Fetch memory pools (cluster-level memory aggregation)
     */
    public function fetchMempools(Device $device): array
    {
        $mempools = [];

        try {
            $clusters = $this->fetchClusters($this->device);
            foreach ($clusters as $idx => $cluster) {
                $totalMb = $cluster['total_memory_mb'] ?? 0;
                $effectiveMb = $cluster['effective_memory_mb'] ?? 0;
                $usedMb = $totalMb - $effectiveMb;

                if ($totalMb > 0) {
                    $mempools[] = [
                        'mempool_index' => $idx,
                        'mempool_type' => 'vcenter-cluster',
                        'mempool_descr' => ($cluster['cluster_name'] ?? "Cluster {$idx}") . ' Memory',
                        'mempool_total' => $totalMb * 1024 * 1024, // Convert MB to bytes
                        'mempool_used' => $usedMb * 1024 * 1024,
                        'mempool_free' => $effectiveMb * 1024 * 1024,
                        'mempool_perc' => $cluster['memory_usage_pct'] ?? 0,
                    ];
                }
            }

            Log::debug('VCenter SOAP: Fetched mempools', [
                'device_id' => $this->device->device_id,
                'count' => count($mempools),
            ]);

        } catch (\Throwable $e) {
            Log::error('VCenter SOAP fetchMempools failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mempools;
    }

    /**
     * Fetch storage (datastores)
     */
    public function fetchStorage(Device $device): array
    {
        $storage = [];

        try {
            $datastores = $this->fetchDatastores();
            foreach ($datastores as $idx => $ds) {
                $name = $ds['name'] ?? "Datastore-{$idx}";
                $capacity = $ds['capacity'] ?? 0;
                $freeSpace = $ds['freeSpace'] ?? 0;
                $used = $capacity - $freeSpace;

                if ($capacity > 0) {
                    $storage[] = [
                        'storage_index' => $idx,
                        'storage_type' => 'hrStorageFixedDisk',
                        'storage_descr' => $name,
                        'storage_size' => $capacity,
                        'storage_used' => $used,
                        'storage_free' => $freeSpace,
                        'storage_perc' => round(($used / $capacity) * 100, 2),
                        'storage_units' => 1,
                    ];
                }
            }

            Log::debug('VCenter SOAP: Fetched storage', [
                'device_id' => $this->device->device_id,
                'count' => count($storage),
            ]);

        } catch (\Throwable $e) {
            Log::error('VCenter SOAP fetchStorage failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $storage;
    }

    /**
     * Fetch VMs from vCenter
     */
    public function fetchVms(Device $device): array
    {
        $vms = [];

        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            $view = $this->createContainerView($sc, ['VirtualMachine']);
            if (!$view) {
                $this->logout();
                return [];
            }

            $vmProps = $this->retrieveProperties($sc, $view, 'VirtualMachine', [
                'name', 'runtime', 'config', 'guest', 'summary'
            ]);

            foreach ($vmProps as $vm) {
                $name = $vm['name'] ?? 'Unknown';
                $runtime = $vm['runtime'] ?? null;
                $config = $vm['config'] ?? null;
                $guest = $vm['guest'] ?? null;
                $summary = $vm['summary'] ?? null;

                $powerState = $runtime && is_object($runtime) ? ($runtime->powerState ?? 'unknown') : 'unknown';

                // Map power state to LibreNMS convention (1 = powered on)
                $stateValue = match ($powerState) {
                    'poweredOn' => 1,
                    'poweredOff' => 0,
                    'suspended' => 2,
                    default => -1,
                };

                $vmData = [
                    'vm_type' => 'vmware',
                    'vmwVmVMID' => $vm['moRef']->_ ?? '',
                    'vmwVmDisplayName' => $name,
                    'vmwVmState' => $stateValue,
                    'vmwVmGuestOS' => '',
                    'vmwVmMemSize' => 0,
                    'vmwVmCpus' => 0,
                ];

                if ($config && is_object($config)) {
                    $vmData['vmwVmGuestOS'] = $config->guestFullName ?? $config->guestId ?? '';
                    $vmData['vmwVmMemSize'] = $config->hardware->memoryMB ?? 0;
                    $vmData['vmwVmCpus'] = $config->hardware->numCPU ?? 0;
                }

                if ($guest && is_object($guest)) {
                    $vmData['vmwVmGuestOS'] = $vmData['vmwVmGuestOS'] ?: ($guest->guestFullName ?? '');
                }

                $vms[] = $vmData;
            }

            $this->destroyView($view);
            $this->logout();

            Log::debug('VCenter SOAP: Fetched VMs', [
                'device_id' => $this->device->device_id,
                'count' => count($vms),
            ]);

        } catch (\Throwable $e) {
            Log::error('VCenter SOAP fetchVms failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $vms;
    }

    /**
     * Fetch hosts from vCenter
     */
    public function fetchHosts(Device $device): array
    {
        $hosts = [];

        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            $view = $this->createContainerView($sc, ['HostSystem']);
            if (!$view) {
                $this->logout();
                return [];
            }

            $hostProps = $this->retrieveProperties($sc, $view, 'HostSystem', [
                'name', 'runtime', 'hardware', 'summary', 'config'
            ]);

            foreach ($hostProps as $host) {
                $name = $host['name'] ?? 'Unknown';
                $runtime = $host['runtime'] ?? null;
                $hardware = $host['hardware'] ?? null;
                $summary = $host['summary'] ?? null;

                $connectionState = $runtime && is_object($runtime) ? ($runtime->connectionState ?? 'unknown') : 'unknown';

                $hostData = [
                    'name' => $name,
                    'connection_state' => $connectionState,
                    'model' => '',
                    'vendor' => '',
                    'serial' => '',
                    'version' => '',
                ];

                if ($hardware && is_object($hardware)) {
                    $systemInfo = $hardware->systemInfo ?? null;
                    if ($systemInfo && is_object($systemInfo)) {
                        $hostData['model'] = $systemInfo->model ?? '';
                        $hostData['vendor'] = $systemInfo->vendor ?? '';
                        $hostData['serial'] = $systemInfo->serialNumber ?? '';
                    }
                }

                if ($summary && is_object($summary) && isset($summary->config)) {
                    $hostData['version'] = $summary->config->product->fullName ?? '';
                }

                $hosts[] = $hostData;
            }

            $this->destroyView($view);
            $this->logout();

        } catch (\Throwable $e) {
            Log::debug('VCenter SOAP fetchHosts failed: ' . $e->getMessage());
        }

        return $hosts;
    }

    /**
     * Fetch datastores from vCenter
     */
    protected function fetchDatastores(): array
    {
        $datastores = [];

        try {
            if (!$this->login()) return [];

            $sc = $this->getServiceContent();
            if (!$sc) return [];

            $view = $this->createContainerView($sc, ['Datastore']);
            if (!$view) {
                $this->logout();
                return [];
            }

            $dsProps = $this->retrieveProperties($sc, $view, 'Datastore', [
                'name', 'summary'
            ]);

            foreach ($dsProps as $ds) {
                $name = $ds['name'] ?? 'Unknown';
                $summary = $ds['summary'] ?? null;

                if ($summary && is_object($summary)) {
                    $datastores[] = [
                        'name' => $name,
                        'capacity' => $summary->capacity ?? 0,
                        'freeSpace' => $summary->freeSpace ?? 0,
                        'type' => $summary->type ?? 'unknown',
                        'accessible' => $summary->accessible ?? false,
                    ];
                }
            }

            $this->destroyView($view);
            $this->logout();

        } catch (\Throwable $e) {
            Log::debug('VCenter SOAP fetchDatastores failed: ' . $e->getMessage());
        }

        return $datastores;
    }

    public function fetchTransceivers(Device $device): array
    {
        return [];
    }

    public function isReachable(): bool
    {
        return $this->login();
    }

    public function getApiInfo(): array
    {
        return [
            'vendor' => 'VMware',
            'api_type' => 'vSphere SOAP API',
            'version' => '6.5+',
            'features' => ['vlans', 'clusters', 'vms', 'hosts', 'datastores', 'performance'],
        ];
    }
}