<?php

namespace App\ApiClients\VMware;

use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * ESXi SOAP API Client
 *
 * Implements VMware vSphere Web Services API (SOAP) for direct ESXi host monitoring.
 * This is required because standalone ESXi hosts have limited REST API support.
 *
 * API Documentation: https://developer.vmware.com/apis/vsphere-automation/latest/
 *
 * Supported ESXi Versions: 6.5, 6.7, 7.0, 8.0
 */
class EsxiSoapClient
{
    protected \SoapClient $client;
    protected ?string $sessionId = null;
    protected string $hostname;
    protected string $username;
    protected string $password;
    protected bool $verifySSL;

    /**
     * Initialize ESXi SOAP client
     *
     * @param Device $device
     * @param array $config Configuration from device_api_configs
     */
    public function __construct(Device $device, array $config = [])
    {
        $this->hostname = $config['hostname'] ?? $device->hostname;
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->verifySSL = $config['verify_ssl'] ?? false;

        $this->initializeSoapClient();
    }

    /**
     * Initialize the SOAP client with ESXi SDK endpoint
     */
    protected function initializeSoapClient(): void
    {
        $wsdl = "https://{$this->hostname}/sdk/vimService.wsdl";
        $location = "https://{$this->hostname}/sdk/";

        // SSL context to handle self-signed certificates
        $contextOptions = [
            'ssl' => [
                'verify_peer' => $this->verifySSL,
                'verify_peer_name' => $this->verifySSL,
                'allow_self_signed' => true,
            ],
        ];

        $soapOptions = [
            'location' => $location,
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => 10,
            'stream_context' => stream_context_create($contextOptions),
            'cache_wsdl' => 0, // WSDL_CACHE_NONE - Disable WSDL caching for reliability
        ];

        try {
            $this->client = new \SoapClient($wsdl, $soapOptions);
        } catch (\SoapFault $e) {
            Log::error("EsxiSoapClient: Failed to create SOAP client for {$this->hostname}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Login to ESXi host and obtain session ID
     *
     * @return bool Success status
     */
    public function login(): bool
    {
        try {
            // Get ServiceContent to retrieve SessionManager
            $serviceContent = $this->getServiceContent();

            if (!$serviceContent || !isset($serviceContent->sessionManager)) {
                Log::error("EsxiSoapClient: Failed to retrieve ServiceContent for {$this->hostname}");
                return false;
            }

            // Login using SessionManager
            $request = [
                '_this' => $serviceContent->sessionManager,
                'userName' => $this->username,
                'password' => $this->password,
            ];

            $response = $this->client->__soapCall('Login', [$request]);

            if ($response && isset($response->returnval->key)) {
                $this->sessionId = $response->returnval->key;
                Log::debug("EsxiSoapClient: Successfully logged in to {$this->hostname}");
                return true;
            }

            Log::warning("EsxiSoapClient: Login response missing session key for {$this->hostname}");
            return false;
        } catch (\SoapFault $e) {
            Log::error("EsxiSoapClient: Login failed for {$this->hostname}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Logout from ESXi host
     */
    public function logout(): void
    {
        if (!$this->sessionId) {
            return;
        }

        try {
            $serviceContent = $this->getServiceContent();
            if ($serviceContent && isset($serviceContent->sessionManager)) {
                $this->client->__soapCall('Logout', [['_this' => $serviceContent->sessionManager]]);
                $this->sessionId = null;
                Log::debug("EsxiSoapClient: Logged out from {$this->hostname}");
            }
        } catch (\SoapFault $e) {
            Log::debug("EsxiSoapClient: Logout error (non-critical): {$e->getMessage()}");
        }
    }

    /**
     * Get ServiceContent (root object for vSphere API)
     *
     * @return object|null ServiceContent object
     */
    protected function getServiceContent(): ?object
    {
        try {
            $response = $this->client->__soapCall('RetrieveServiceContent', [
                ['_this' => ['_' => 'ServiceInstance', 'type' => 'ServiceInstance']],
            ]);

            return $response->returnval ?? null;
        } catch (\SoapFault $e) {
            Log::error("EsxiSoapClient: Failed to retrieve ServiceContent: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Fetch host hardware information (model, serial, vendor, CPU, memory)
     *
     * @param Device $device
     * @return array Hardware details
     */
    public function fetchHostHardware(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Retrieve host hardware info and network configuration
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['hardware', 'config.product', 'config.network.dnsConfig', 'name']
            );

            $hardware = [];
            if (isset($properties->hardware)) {
                $hw = $properties->hardware;
                $hardware['model'] = $hw->systemInfo->model ?? null;
                $hardware['serial'] = $hw->systemInfo->serialNumber ?? null;
                $hardware['vendor'] = $hw->systemInfo->vendor ?? null;
                $hardware['uuid'] = $hw->systemInfo->uuid ?? null;
                $hardware['cpu_count'] = $hw->cpuInfo->numCpuPackages ?? 0;
                $hardware['cpu_cores'] = $hw->cpuInfo->numCpuCores ?? 0;
                $hardware['cpu_threads'] = $hw->cpuInfo->numCpuThreads ?? 0;
                $hardware['cpu_mhz'] = $hw->cpuInfo->hz ?? 0;
                $hardware['memory_bytes'] = $hw->memorySize ?? 0;
            }

            if (isset($properties->{'config.product'})) {
                $product = $properties->{'config.product'};
                $hardware['version'] = $product->version ?? null;
                $hardware['build'] = $product->build ?? null;
                $hardware['full_name'] = $product->fullName ?? null;
            }

            // Get hostname from DNS config or system name
            if (isset($properties->{'config.network.dnsConfig'})) {
                $dnsConfig = $properties->{'config.network.dnsConfig'};
                $hardware['hostname'] = $dnsConfig->hostName ?? null;
                $hardware['domain'] = $dnsConfig->domainName ?? null;
            }
            // Fallback to the managed object name
            if (empty($hardware['hostname']) && isset($properties->name)) {
                $hardware['hostname'] = $properties->name;
            }

            return $hardware;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchHostHardware failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Fetch network interfaces (pNICs, VMkernel adapters, and vNICs)
     *
     * @param Device $device
     * @return array Network interfaces
     */
    public function fetchNetworkInterfaces(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Retrieve network info including pnics, vnics, and vswitch info
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['config.network']
            );

            $interfaces = [];
            $ifIndex = 1;
            $network = $properties->{'config.network'} ?? null;

            if (!$network) {
                return [];
            }

            // 1. Collect Physical NICs (pnics/vmnics)
            if (isset($network->pnic)) {
                $pnics = $network->pnic;
                if (!is_array($pnics)) {
                    $pnics = [$pnics];
                }

                foreach ($pnics as $pnic) {
                    $deviceName = $pnic->device ?? "vmnic" . ($ifIndex - 1);
                    $speed = ($pnic->linkSpeed->speedMb ?? 0) * 1000000; // Convert Mbps to bps
                    $mtu = $pnic->spec->mtu ?? 1500;

                    $interfaces[] = [
                        'ifIndex' => $ifIndex++,
                        'ifName' => $deviceName,
                        'ifDescr' => $deviceName . ' (Physical NIC)',
                        'ifType' => 'ethernetCsmacd',
                        'ifSpeed' => $speed,
                        'ifPhysAddress' => $pnic->mac ?? '',
                        'ifOperStatus' => (isset($pnic->linkSpeed) && $pnic->linkSpeed->speedMb > 0) ? 'up' : 'down',
                        'ifAdminStatus' => 'up',
                        'ifMtu' => $mtu,
                    ];
                }
            }

            // 2. Collect VMkernel adapters (vnics - vmk0, vmk1, etc.)
            // These are the virtual NICs that have IP addresses
            if (isset($network->vnic)) {
                $vnics = $network->vnic;
                if (!is_array($vnics)) {
                    $vnics = [$vnics];
                }

                foreach ($vnics as $vnic) {
                    $deviceName = $vnic->device ?? "vmk" . ($ifIndex - 1);
                    $spec = $vnic->spec ?? null;

                    // Get IP configuration
                    $ipAddress = null;
                    $netmask = null;
                    $prefixLen = 24; // Default

                    if ($spec && isset($spec->ip)) {
                        if (isset($spec->ip->ipAddress)) {
                            $ipAddress = $spec->ip->ipAddress;
                        }
                        if (isset($spec->ip->subnetMask)) {
                            $netmask = $spec->ip->subnetMask;
                            // Convert subnet mask to prefix length
                            $prefixLen = $this->netmaskToPrefixLen($netmask);
                        }
                    }

                    $mtu = $spec->mtu ?? 1500;
                    $macAddress = $spec->mac ?? '';

                    // VMkernel port group
                    $portgroup = $vnic->portgroup ?? '';

                    // Build description
                    $descr = $deviceName . ' (VMkernel)';
                    if ($portgroup) {
                        $descr .= " - $portgroup";
                    }
                    if ($ipAddress) {
                        $descr .= " [$ipAddress]";
                    }

                    $interfaces[] = [
                        'ifIndex' => $ifIndex++,
                        'ifName' => $deviceName,
                        'ifDescr' => $descr,
                        'ifType' => 'ethernetCsmacd',
                        'ifSpeed' => 1000000000, // 1Gbps default for VMkernel
                        'ifPhysAddress' => $macAddress,
                        'ifOperStatus' => 'up', // VMkernel adapters are typically always up
                        'ifAdminStatus' => 'up',
                        'ifMtu' => $mtu,
                        'ipv4_address' => $ipAddress,
                        'ipv4_netmask' => $netmask,
                        'ipv4_prefixlen' => $prefixLen,
                        'portgroup' => $portgroup,
                    ];
                }
            }

            return $interfaces;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchNetworkInterfaces failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Convert subnet mask to prefix length (e.g., 255.255.255.0 -> 24)
     *
     * @param string $netmask
     * @return int
     */
    protected function netmaskToPrefixLen(string $netmask): int
    {
        $long = ip2long($netmask);
        if ($long === false) {
            return 24; // Default
        }

        $prefixLen = 0;
        for ($i = 0; $i < 32; $i++) {
            if (($long & (1 << (31 - $i))) !== 0) {
                $prefixLen++;
            } else {
                break;
            }
        }

        return $prefixLen;
    }

    /**
     * Fetch host performance metrics (CPU usage, memory usage)
     *
     * @param Device $device
     * @return array Performance metrics
     */
    public function fetchHostPerformance(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Retrieve quick stats
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['summary.quickStats', 'summary.hardware']
            );

            $metrics = [];
            if (isset($properties->{'summary.quickStats'})) {
                $stats = $properties->{'summary.quickStats'};
                $metrics['cpu_usage_mhz'] = $stats->overallCpuUsage ?? 0;
                $metrics['memory_usage_bytes'] = ($stats->overallMemoryUsage ?? 0) * 1024 * 1024; // Convert MB to bytes
                $metrics['uptime_seconds'] = $stats->uptime ?? 0;
            }

            if (isset($properties->{'summary.hardware'})) {
                $hw = $properties->{'summary.hardware'};
                $metrics['cpu_total_mhz'] = ($hw->cpuMhz ?? 0) * ($hw->numCpuCores ?? 1);
                $metrics['memory_total_bytes'] = $hw->memorySize ?? 0;

                // Calculate percentages
                if ($metrics['cpu_total_mhz'] > 0) {
                    $metrics['cpu_usage_percent'] = ($metrics['cpu_usage_mhz'] / $metrics['cpu_total_mhz']) * 100;
                }
                if ($metrics['memory_total_bytes'] > 0) {
                    $metrics['memory_usage_percent'] = ($metrics['memory_usage_bytes'] / $metrics['memory_total_bytes']) * 100;
                }
            }

            return $metrics;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchHostPerformance failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Fetch datastores connected to the host
     *
     * @param Device $device
     * @return array Datastores
     */
    public function fetchDatastores(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Retrieve datastore info
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['datastore']
            );

            $datastores = [];
            if (isset($properties->datastore->ManagedObjectReference)) {
                $dsRefs = $properties->datastore->ManagedObjectReference;
                if (!is_array($dsRefs)) {
                    $dsRefs = [$dsRefs];
                }

                foreach ($dsRefs as $dsRef) {
                    $dsProps = $this->retrieveProperties(
                        $serviceContent,
                        $dsRef,
                        'Datastore',
                        ['summary']
                    );

                    if (isset($dsProps->summary)) {
                        $summary = $dsProps->summary;
                        $datastores[] = [
                            'name' => $summary->name ?? 'Unknown',
                            'type' => $summary->type ?? 'Unknown',
                            'capacity_bytes' => $summary->capacity ?? 0,
                            'free_bytes' => $summary->freeSpace ?? 0,
                            'accessible' => $summary->accessible ?? false,
                        ];
                    }
                }
            }

            return $datastores;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchDatastores failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Fetch network interface statistics (traffic counters) using Performance Manager
     *
     * @param Device $device
     * @return array Network statistics for each interface
     */
    public function fetchNetworkStatistics(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Get network interface list first to build interface mapping
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['config.network']
            );

            $statistics = [];
            $network = $properties->{'config.network'} ?? null;

            if (!$network) {
                return [];
            }

            // Build interface index mapping
            $ifIndexMap = [];
            $ifIndex = 1;

            // Physical NICs
            if (isset($network->pnic)) {
                $pnics = $network->pnic;
                if (!is_array($pnics)) {
                    $pnics = [$pnics];
                }

                foreach ($pnics as $pnic) {
                    $deviceName = $pnic->device ?? "vmnic" . ($ifIndex - 1);
                    $ifIndexMap[$deviceName] = $ifIndex++;
                }
            }

            // VMkernel adapters
            if (isset($network->vnic)) {
                $vnics = $network->vnic;
                if (!is_array($vnics)) {
                    $vnics = [$vnics];
                }

                foreach ($vnics as $vnic) {
                    $deviceName = $vnic->device ?? "vmk" . ($ifIndex - 1);
                    $ifIndexMap[$deviceName] = $ifIndex++;
                }
            }

            // Query performance counters for network statistics
            $perfStats = $this->queryNetworkPerformanceCounters($serviceContent, $hostSystem);

            // Map performance data to interfaces
            foreach ($ifIndexMap as $ifName => $ifIdx) {
                $stats = $perfStats[$ifName] ?? [
                    'received' => 0,
                    'transmitted' => 0,
                    'packetsRx' => 0,
                    'packetsTx' => 0,
                    'droppedRx' => 0,
                    'droppedTx' => 0,
                    'errorsRx' => 0,
                    'errorsTx' => 0,
                ];

                // ESXi SOAP returns rates (KBps), not cumulative counters
                // Convert to bytes/sec and use _rate fields for GAUGE RRD storage
                $statistics[] = [
                    'ifIndex' => $ifIdx,
                    'ifName' => $ifName,
                    'ifInOctets_rate' => $stats['received'] * 1024, // Convert KBps to bytes/sec
                    'ifOutOctets_rate' => $stats['transmitted'] * 1024, // Convert KBps to bytes/sec
                    'ifInUcastPkts_rate' => $stats['packetsRx'] / 20, // packetsRx is summation over 20s, convert to rate
                    'ifOutUcastPkts_rate' => $stats['packetsTx'] / 20, // packetsTx is summation over 20s, convert to rate
                    'ifInErrors_rate' => ($stats['errorsRx'] + $stats['droppedRx']) / 20, // summation over 20s
                    'ifOutErrors_rate' => ($stats['errorsTx'] + $stats['droppedTx']) / 20, // summation over 20s
                ];
            }

            Log::debug("EsxiSoapClient: fetchNetworkStatistics returning", ['statistics_count' => count($statistics)]);

            return $statistics;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchNetworkStatistics failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Query network performance counters from PerformanceManager
     *
     * @param object $serviceContent
     * @param object $hostSystem
     * @return array Network performance statistics by interface
     */
    protected function queryNetworkPerformanceCounters(object $serviceContent, object $hostSystem): array
    {
        try {
            $perfManager = $serviceContent->perfManager;

            // Get available performance counter metadata
            $counterRequest = [
                '_this' => $perfManager,
            ];

            $countersResponse = $this->client->__soapCall('RetrieveProperties', [[
                '_this' => $serviceContent->propertyCollector,
                'specSet' => [[
                    'propSet' => [['type' => 'PerformanceManager', 'pathSet' => ['perfCounter']]],
                    'objectSet' => [['obj' => $perfManager, 'skip' => false]],
                ]],
            ]]);

            // Build counter ID mapping for network metrics
            $counterIds = [];
            if (isset($countersResponse->returnval)) {
                $returnval = $countersResponse->returnval;
                if (!is_array($returnval)) {
                    $returnval = [$returnval];
                }

                foreach ($returnval as $objectContent) {
                    if (isset($objectContent->propSet)) {
                        $propSet = $objectContent->propSet;
                        if (!is_array($propSet)) {
                            $propSet = [$propSet];
                        }

                        foreach ($propSet as $prop) {
                            if ($prop->name === 'perfCounter' && isset($prop->val->PerfCounterInfo)) {
                                $counters = $prop->val->PerfCounterInfo;
                                if (!is_array($counters)) {
                                    $counters = [$counters];
                                }

                                foreach ($counters as $counter) {
                                    $groupInfo = $counter->groupInfo->key ?? '';
                                    $nameInfo = $counter->nameInfo->key ?? '';
                                    $rollupType = $counter->rollupType ?? '';

                                    if ($groupInfo === 'net') {
                                        $key = "{$nameInfo}.{$rollupType}";
                                        $counterIds[$key] = $counter->key;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Define the metrics we want to collect
            $metricsMap = [
                'received' => $counterIds['received.average'] ?? null,
                'transmitted' => $counterIds['transmitted.average'] ?? null,
                'packetsRx' => $counterIds['packetsRx.summation'] ?? null,
                'packetsTx' => $counterIds['packetsTx.summation'] ?? null,
                'droppedRx' => $counterIds['droppedRx.summation'] ?? null,
                'droppedTx' => $counterIds['droppedTx.summation'] ?? null,
                'errorsRx' => $counterIds['errorsRx.summation'] ?? null,
                'errorsTx' => $counterIds['errorsTx.summation'] ?? null,
            ];

            // Filter out null counter IDs
            $metricsMap = array_filter($metricsMap);

            if (empty($metricsMap)) {
                Log::debug("EsxiSoapClient: No network performance counters available");
                return [];
            }

            // Build metric IDs for query
            $metricIds = [];
            foreach ($metricsMap as $metricName => $counterId) {
                $metricIds[] = [
                    'counterId' => $counterId,
                    'instance' => '*', // Wildcard to get all instances (all NICs)
                ];
            }

            // Query performance statistics (realtime, most recent sample)
            $querySpec = [
                'entity' => $hostSystem,
                'metricId' => $metricIds,
                'intervalId' => 20, // 20 seconds realtime interval
                'maxSample' => 1, // Only get latest sample
            ];

            $queryRequest = [
                '_this' => $perfManager,
                'querySpec' => [$querySpec],
            ];

            $perfResponse = $this->client->__soapCall('QueryPerf', [$queryRequest]);

            // Parse performance response
            $perfStats = [];
            if (isset($perfResponse->returnval)) {
                $results = $perfResponse->returnval;
                if (!is_array($results)) {
                    $results = [$results];
                }

                foreach ($results as $result) {
                    if (isset($result->value)) {
                        $values = $result->value;
                        if (!is_array($values)) {
                            $values = [$values];
                        }

                        foreach ($values as $value) {
                            $counterId = $value->id->counterId ?? null;
                            $instance = $value->id->instance ?? '';
                            $valueData = $value->value ?? [];

                            if (!is_array($valueData)) {
                                $valueData = [$valueData];
                            }

                            // Get the latest value
                            $latestValue = end($valueData) ?: 0;

                            // Find metric name for this counter ID
                            $metricName = array_search($counterId, $metricsMap);

                            if ($metricName && $instance) {
                                if (!isset($perfStats[$instance])) {
                                    $perfStats[$instance] = [];
                                }
                                $perfStats[$instance][$metricName] = $latestValue;
                            }
                        }
                    }
                }
            }

            return $perfStats;

        } catch (\Exception $e) {
            Log::debug("EsxiSoapClient: queryNetworkPerformanceCounters failed (non-critical): {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get the HostSystem managed object reference
     *
     * @param object $serviceContent
     * @return object|null HostSystem reference
     */
    protected function getHostSystem(object $serviceContent): ?object
    {
        try {
            // For standalone ESXi, get the host from rootFolder
            $rootFolder = $serviceContent->rootFolder;

            // Create container view for HostSystem
            $request = [
                '_this' => $serviceContent->viewManager,
                'container' => $rootFolder,
                'type' => ['HostSystem'],
                'recursive' => true,
            ];

            $containerView = $this->client->__soapCall('CreateContainerView', [$request]);

            if (!isset($containerView->returnval)) {
                return null;
            }

            // Get hosts from container view
            $viewRef = $containerView->returnval;
            $properties = $this->retrieveProperties(
                $serviceContent,
                $viewRef,
                'ContainerView',
                ['view']
            );

            if (isset($properties->view->ManagedObjectReference)) {
                $hosts = $properties->view->ManagedObjectReference;
                if (!is_array($hosts)) {
                    $hosts = [$hosts];
                }

                // Return first host (standalone ESXi only has one)
                return $hosts[0] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: getHostSystem failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Retrieve properties from a managed object using PropertyCollector
     *
     * @param object $serviceContent
     * @param object $objectRef Managed object reference
     * @param string $objectType Type of the managed object
     * @param array $properties Property paths to retrieve
     * @return object|null Retrieved properties
     */
    protected function retrieveProperties(object $serviceContent, object $objectRef, string $objectType, array $properties): ?object
    {
        try {
            $propertySpec = [
                'type' => $objectType,
                'pathSet' => $properties,
            ];

            $objectSpec = [
                'obj' => $objectRef,
                'skip' => false,
            ];

            $propertyFilterSpec = [
                'propSet' => [$propertySpec],
                'objectSet' => [$objectSpec],
            ];

            $request = [
                '_this' => $serviceContent->propertyCollector,
                'specSet' => [$propertyFilterSpec],
            ];

            $response = $this->client->__soapCall('RetrieveProperties', [$request]);

            if (isset($response->returnval)) {
                // Convert DynamicProperty array to object
                $result = new \stdClass();
                $returnval = $response->returnval;
                if (!is_array($returnval)) {
                    $returnval = [$returnval];
                }

                foreach ($returnval as $objectContent) {
                    if (isset($objectContent->propSet)) {
                        $propSet = $objectContent->propSet;
                        if (!is_array($propSet)) {
                            $propSet = [$propSet];
                        }

                        foreach ($propSet as $prop) {
                            $result->{$prop->name} = $prop->val;
                        }
                    }
                }

                return $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: retrieveProperties failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Fetch vSwitch VLANs
     *
     * @param Device $device
     * @return array VLANs from vSwitches and port groups
     */
    public function fetchVlans(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Retrieve network info
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['config.network']
            );

            $vlans = [];
            $network = $properties->{'config.network'} ?? null;

            if (!$network) {
                return [];
            }

            // Collect VLANs from port groups
            if (isset($network->portgroup)) {
                $portgroups = $network->portgroup;
                if (!is_array($portgroups)) {
                    $portgroups = [$portgroups];
                }

                foreach ($portgroups as $pg) {
                    $spec = $pg->spec ?? null;
                    $vlanId = $spec->vlanId ?? 0;
                    $name = $spec->name ?? $pg->key ?? 'Unknown';
                    $vswitch = $spec->vswitchName ?? '';

                    // Include VLAN 0 as it represents untagged traffic
                    $vlans[] = [
                        'vlan_vlan' => $vlanId,
                        'vlan_domain' => 1,
                        'vlan_name' => $name,
                        'vlan_type' => 'ethernet',
                        'vlan_mtu' => null,
                        'vswitch' => $vswitch,
                    ];
                }
            }

            // Collect VLANs from distributed virtual port groups if present
            if (isset($network->proxySwitch)) {
                $proxySwitches = $network->proxySwitch;
                if (!is_array($proxySwitches)) {
                    $proxySwitches = [$proxySwitches];
                }

                foreach ($proxySwitches as $dvs) {
                    $dvsName = $dvs->dvsName ?? 'DVS';
                    $spec = $dvs->spec ?? null;

                    if ($spec && isset($spec->backing->port)) {
                        $ports = $spec->backing->port;
                        if (!is_array($ports)) {
                            $ports = [$ports];
                        }

                        foreach ($ports as $port) {
                            $portgroupKey = $port->portgroupKey ?? '';
                            $connectionCookie = $port->connectionCookie ?? 0;

                            // DVS VLANs would need additional API calls to get VLAN IDs
                            // For now, we'll just note their existence
                        }
                    }
                }
            }

            return $vlans;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchVlans failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Fetch CDP/LLDP neighbor information for physical NICs
     *
     * @param Device $device
     * @return array CDP/LLDP neighbor data
     */
    public function fetchNeighbors(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Retrieve network info with pnic CDP/LLDP data
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['config.network']
            );

            $neighbors = [];
            $network = $properties->{'config.network'} ?? null;

            if (!$network) {
                return [];
            }

            // Get physical NICs
            if (isset($network->pnic)) {
                $pnics = $network->pnic;
                if (!is_array($pnics)) {
                    $pnics = [$pnics];
                }

                foreach ($pnics as $idx => $pnic) {
                    $deviceName = $pnic->device ?? "vmnic{$idx}";

                    // Check for CDP/LLDP info in linkDiscoveryProtocolInfo
                    $linkDiscovery = $pnic->linkDiscoveryProtocolInfo ?? null;

                    if ($linkDiscovery) {
                        // CDP information
                        if (isset($linkDiscovery->cdpInfo)) {
                            $cdp = $linkDiscovery->cdpInfo;

                            $neighbors[] = [
                                'port' => $deviceName,
                                'protocol' => 'CDP',
                                'device_id' => $cdp->deviceId ?? '',
                                'port_id' => $cdp->portId ?? '',
                                'platform' => $cdp->platform ?? '',
                                'version' => $cdp->version ?? '',
                                'capabilities' => isset($cdp->capability) ? implode(',', (array) $cdp->capability) : '',
                                'address' => $cdp->address ?? '',
                                'mgmt_address' => $cdp->mgmtAddr ?? '',
                                'vlan' => $cdp->vlan ?? null,
                                'duplex' => $cdp->duplex ?? '',
                                'mtu' => $cdp->mtu ?? null,
                            ];
                        }

                        // LLDP information
                        if (isset($linkDiscovery->lldpInfo)) {
                            $lldp = $linkDiscovery->lldpInfo;

                            $neighbors[] = [
                                'port' => $deviceName,
                                'protocol' => 'LLDP',
                                'chassis_id' => $lldp->chassisId ?? '',
                                'port_id' => $lldp->portId ?? '',
                                'system_name' => $lldp->systemName ?? '',
                                'system_description' => $lldp->systemDescription ?? '',
                                'port_description' => $lldp->portDescription ?? '',
                                'mgmt_address' => isset($lldp->mgmtAddr) ? implode(',', (array) $lldp->mgmtAddr) : '',
                                'capabilities' => isset($lldp->parameter) ? json_encode($lldp->parameter) : '',
                            ];
                        }
                    }
                }
            }

            return $neighbors;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchNeighbors failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Fetch IPv4 addresses from VMkernel adapters
     *
     * @param Device $device
     * @return array IPv4 addresses
     */
    public function fetchIpv4Addresses(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Retrieve network info
            $properties = $this->retrieveProperties(
                $serviceContent,
                $hostSystem,
                'HostSystem',
                ['config.network']
            );

            $addresses = [];
            $network = $properties->{'config.network'} ?? null;

            if (!$network) {
                return [];
            }

            // Collect VMkernel adapter IP addresses
            if (isset($network->vnic)) {
                $vnics = $network->vnic;
                if (!is_array($vnics)) {
                    $vnics = [$vnics];
                }

                foreach ($vnics as $idx => $vnic) {
                    $deviceName = $vnic->device ?? "vmk{$idx}";
                    $spec = $vnic->spec ?? null;

                    if ($spec && isset($spec->ip->ipAddress)) {
                        $ipAddress = $spec->ip->ipAddress;
                        $netmask = $spec->ip->subnetMask ?? '255.255.255.0';
                        $prefixLen = $this->netmaskToPrefixLen($netmask);

                        $addresses[] = [
                            'ifIndex' => $idx + 1000, // Offset to avoid collision with pnics
                            'ifName' => $deviceName,
                            'ipv4_address' => $ipAddress,
                            'ipv4_prefixlen' => $prefixLen,
                            'context_name' => '',
                        ];
                    }
                }
            }

            return $addresses;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchIpv4Addresses failed: {$e->getMessage()}");
            return [];
        } finally {
            $this->logout();
        }
    }

    /**
     * Test connection to ESXi host
     *
     * @return bool True if connection successful
     */
    public function testConnection(): bool
    {
        try {
            if (!$this->login()) {
                return false;
            }

            $serviceContent = $this->getServiceContent();
            $success = $serviceContent !== null;

            $this->logout();
            return $success;
        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: testConnection failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Fetch virtual machines running on the ESXi host
     *
     * @param Device $device
     * @return array Array of VM information
     */
    public function fetchVms(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            $hostSystem = $this->getHostSystem($serviceContent);

            if (!$hostSystem) {
                return [];
            }

            // Create container view for VirtualMachine objects
            // This is more reliable than querying HostSystem.vm property in ESXi 8.0+
            $rootFolder = $serviceContent->rootFolder;
            $request = [
                '_this' => $serviceContent->viewManager,
                'container' => $rootFolder,
                'type' => ['VirtualMachine'],
                'recursive' => true,
            ];

            $containerView = $this->client->__soapCall('CreateContainerView', [$request]);

            if (!isset($containerView->returnval)) {
                Log::debug("EsxiSoapClient: Could not create VirtualMachine container view");
                return [];
            }

            // Get VMs from container view
            $viewRef = $containerView->returnval;
            $viewProperties = $this->retrieveProperties(
                $serviceContent,
                $viewRef,
                'ContainerView',
                ['view']
            );

            $vms = [];
            $vmRefs = [];

            if (isset($viewProperties->view)) {
                // Handle both single and multiple VMs
                if (isset($viewProperties->view->ManagedObjectReference)) {
                    $vmRefs = $viewProperties->view->ManagedObjectReference;
                    if (!is_array($vmRefs)) {
                        $vmRefs = [$vmRefs];
                    }
                }
            }

            if (empty($vmRefs)) {
                Log::debug("EsxiSoapClient: No VMs found on host");
                // Destroy the container view
                try {
                    $this->client->__soapCall('DestroyView', [['_this' => $viewRef]]);
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
                return [];
            }

            // Fetch properties for each VM
            foreach ($vmRefs as $vmRef) {
                try {
                    // Skip invalid VM references (empty or missing _ property)
                    if (!is_object($vmRef) || !isset($vmRef->_) || empty($vmRef->_)) {
                        Log::debug("EsxiSoapClient: Skipping invalid VM reference");
                        continue;
                    }

                    $vmProps = $this->retrieveProperties(
                        $serviceContent,
                        $vmRef,
                        'VirtualMachine',
                        [
                            'name',
                            'config.guestFullName',
                            'config.hardware.numCPU',
                            'config.hardware.memoryMB',
                            'runtime.powerState',
                            'config.instanceUuid',
                        ]
                    );

                    if (!$vmProps) {
                        continue;
                    }

                    // Map ESXi power states to LibreNMS PowerState integer values
                    // PowerState: OFF = 0, ON = 1, SUSPENDED = 2, UNKNOWN = 3
                    $powerState = $vmProps->{'runtime.powerState'} ?? 'unknown';
                    $vmState = match($powerState) {
                        'poweredOn' => 1,  // PowerState::ON
                        'poweredOff' => 0, // PowerState::OFF
                        'suspended' => 2,  // PowerState::SUSPENDED
                        default => 3,      // PowerState::UNKNOWN
                    };

                    $vmId = $vmProps->{'config.instanceUuid'} ?? ($vmRef->_ ?? 'unknown');

                    // Convert UUID to numeric ID for database storage
                    // ESXi uses UUIDs (e.g., "502e6c39-070d-9d21-9bfb-50831c0b300e")
                    // We need to convert to integer for vmwVmVMID column
                    // Use abs() to ensure positive value that fits in INT(11)
                    $numericId = abs(crc32($vmId)) % 2147483647;

                    $vms[] = [
                        'vm_type' => 'vmware',
                        'vmwVmVMID' => $numericId,
                        'vmwVmDisplayName' => $vmProps->name ?? 'Unknown',
                        'vmwVmGuestOS' => $vmProps->{'config.guestFullName'} ?? 'Other',
                        'vmwVmMemSize' => isset($vmProps->{'config.hardware.memoryMB'}) ? (int) $vmProps->{'config.hardware.memoryMB'} : null,
                        'vmwVmCpus' => isset($vmProps->{'config.hardware.numCPU'}) ? (int) $vmProps->{'config.hardware.numCPU'} : null,
                        'vmwVmState' => $vmState,
                    ];
                } catch (\Exception $e) {
                    Log::debug("EsxiSoapClient: Could not fetch VM properties: {$e->getMessage()}");
                }
            }

            // Destroy the container view
            try {
                $this->client->__soapCall('DestroyView', [['_this' => $viewRef]]);
            } catch (\Exception $e) {
                Log::debug("EsxiSoapClient: Could not destroy container view: {$e->getMessage()}");
            }

            $this->logout();
            Log::info("EsxiSoapClient: Fetched " . count($vms) . " VMs for device {$device->device_id}");

        } catch (\Exception $e) {
            Log::error("EsxiSoapClient: fetchVms failed for device {$device->device_id}: {$e->getMessage()}");
        }

        return $vms;
    }
}
