<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapVar;

/**
 * vCenter SOAP API Client for accessing legacy vSphere Web Services
 * Used for VLAN collection from Standard/Distributed Port Groups and vSwitches
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

        $apiConfig = $device->apiConfig;
        if (!$apiConfig) {
            throw new \Exception("VCenterSoapClient: No API configuration found for device {$device->device_id}");
        }

        $username = $apiConfig->getValue('username');
        $password = $apiConfig->getValue('password');

        if (empty($username) || empty($password)) {
            throw new \Exception("VCenterSoapClient: No API credentials configured for device {$device->device_id}");
        }

        $this->credentials = [
            'username' => $username,
            'password' => $password,
        ];

        // SOAP endpoint is /sdk (same as ESXi)
        $baseUrl = $apiConfig->base_url ?? "https://{$device->hostname}/sdk";
        if (!str_ends_with($baseUrl, '/sdk')) {
            $baseUrl = rtrim($baseUrl, '/') . '/sdk';
        }

        $this->initializeSoapClient($baseUrl);
    }

    protected function initializeSoapClient(string $endpoint): void
    {
        try {
            // Extract hostname from endpoint for WSDL URL
            $hostname = $this->device->hostname;
            $wsdl = "https://{$hostname}/sdk/vimService.wsdl";

            $this->client = new SoapClient($wsdl, [
                'location' => $endpoint,
                'trace' => true,
                'exceptions' => true,
                'connection_timeout' => 30,
                'cache_wsdl' => 0, // WSDL_CACHE_NONE
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ]),
            ]);
        } catch (\Exception $e) {
            throw new \Exception("VCenterSoapClient: Failed to initialize SOAP client: {$e->getMessage()}");
        }
    }

    protected function login(): bool
    {
        if ($this->sessionId) {
            return true; // Already logged in
        }

        try {
            // Retrieve ServiceContent
            $serviceContent = $this->getServiceContent();
            if (!$serviceContent) {
                return false;
            }

            // Login to session manager
            $request = [
                '_this' => $serviceContent->sessionManager,
                'userName' => $this->credentials['username'],
                'password' => $this->credentials['password'],
            ];

            $response = $this->client->__soapCall('Login', [$request]);

            if (isset($response->returnval)) {
                $this->sessionId = $response->returnval->key ?? null;
                Log::debug("VCenterSoapClient: Login successful for device {$this->device->device_id}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient: Login failed for device {$this->device->device_id}: {$e->getMessage()}");
            return false;
        }
    }

    protected function logout(): void
    {
        if (!$this->sessionId) {
            return;
        }

        try {
            $serviceContent = $this->getServiceContent();
            if ($serviceContent) {
                $this->client->__soapCall('Logout', [['_this' => $serviceContent->sessionManager]]);
            }
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: Logout error: {$e->getMessage()}");
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
            Log::error("VCenterSoapClient: Failed to retrieve service content: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Fetch VLANs from vCenter (Standard Port Groups, Distributed Port Groups, Distributed Switches)
     */
    public function fetchVlans(Device $device): array
    {
        if (!$this->login()) {
            return [];
        }

        try {
            $serviceContent = $this->getServiceContent();
            if (!$serviceContent) {
                return [];
            }

            $vlans = [];

            // Collect VLANs from Standard Port Groups
            $vlans = array_merge($vlans, $this->fetchStandardPortGroupVlans($serviceContent));

            // Collect VLANs from Distributed Port Groups
            $vlans = array_merge($vlans, $this->fetchDistributedPortGroupVlans($serviceContent));

            // Collect VLANs from Distributed Switches
            $vlans = array_merge($vlans, $this->fetchDistributedSwitchVlans($serviceContent));

            $this->logout();
            Log::info("VCenterSoapClient: Fetched " . count($vlans) . " VLANs for device {$device->device_id}");

            return $vlans;
        } catch (\Exception $e) {
            Log::error("VCenterSoapClient: fetchVlans failed for device {$device->device_id}: {$e->getMessage()}");
            return [];
        }
    }

    protected function fetchStandardPortGroupVlans(object $serviceContent): array
    {
        $vlans = [];

        try {
            // Create container view for Network objects
            $rootFolder = $serviceContent->rootFolder;
            $request = [
                '_this' => $serviceContent->viewManager,
                'container' => $rootFolder,
                'type' => ['Network'],
                'recursive' => true,
            ];

            $containerView = $this->client->__soapCall('CreateContainerView', [$request]);
            if (!isset($containerView->returnval)) {
                return [];
            }

            $viewRef = $containerView->returnval;
            $viewProperties = $this->retrieveProperties($serviceContent, $viewRef, 'ContainerView', ['view']);

            if (isset($viewProperties->view->ManagedObjectReference)) {
                $networks = $viewProperties->view->ManagedObjectReference;
                if (!is_array($networks)) {
                    $networks = [$networks];
                }

                foreach ($networks as $networkRef) {
                    try {
                        $networkProps = $this->retrieveProperties($serviceContent, $networkRef, 'Network', [
                            'name',
                            'host',
                        ]);

                        if ($networkProps && isset($networkProps->name)) {
                            $name = $networkProps->name;

                            // Extract VLAN ID from name if present (e.g., "VLAN 100", "Network-100")
                            $vlanId = $this->extractVlanId($name);

                            if ($vlanId) {
                                $vlans[] = [
                                    'vlan_vlan' => $vlanId,
                                    'vlan_name' => $name,
                                    'vlan_type' => 'standard_portgroup',
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        Log::debug("VCenterSoapClient: Could not fetch network properties: {$e->getMessage()}");
                    }
                }
            }

            // Cleanup
            try {
                $this->client->__soapCall('DestroyView', [['_this' => $viewRef]]);
            } catch (\Exception $e) {
                // Ignore
            }
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: fetchStandardPortGroupVlans error: {$e->getMessage()}");
        }

        return $vlans;
    }

    protected function fetchDistributedPortGroupVlans(object $serviceContent): array
    {
        $vlans = [];

        try {
            // Create container view for DistributedVirtualPortgroup objects
            $rootFolder = $serviceContent->rootFolder;
            $request = [
                '_this' => $serviceContent->viewManager,
                'container' => $rootFolder,
                'type' => ['DistributedVirtualPortgroup'],
                'recursive' => true,
            ];

            $containerView = $this->client->__soapCall('CreateContainerView', [$request]);
            if (!isset($containerView->returnval)) {
                return [];
            }

            $viewRef = $containerView->returnval;
            $viewProperties = $this->retrieveProperties($serviceContent, $viewRef, 'ContainerView', ['view']);

            if (isset($viewProperties->view->ManagedObjectReference)) {
                $portgroups = $viewProperties->view->ManagedObjectReference;
                if (!is_array($portgroups)) {
                    $portgroups = [$portgroups];
                }

                foreach ($portgroups as $pgRef) {
                    try {
                        $pgProps = $this->retrieveProperties($serviceContent, $pgRef, 'DistributedVirtualPortgroup', [
                            'name',
                            'config',
                        ]);

                        if ($pgProps && isset($pgProps->name)) {
                            $name = $pgProps->name;
                            $vlanId = null;

                            // Try to get VLAN from config
                            if (isset($pgProps->config->defaultPortConfig->vlan)) {
                                $vlanConfig = $pgProps->config->defaultPortConfig->vlan;

                                // Handle different VLAN config types
                                if (isset($vlanConfig->vlanId)) {
                                    // Check if it's a simple integer or an object/array (range)
                                    if (is_numeric($vlanConfig->vlanId)) {
                                        $vlanId = (int) $vlanConfig->vlanId;
                                    } elseif (is_object($vlanConfig->vlanId) && isset($vlanConfig->vlanId->start)) {
                                        // VLAN range - use the start value
                                        $vlanId = (int) $vlanConfig->vlanId->start;
                                    }
                                    // Skip if it's a complex range or trunk
                                } elseif (isset($vlanConfig->pvlanId)) {
                                    if (is_numeric($vlanConfig->pvlanId)) {
                                        $vlanId = (int) $vlanConfig->pvlanId;
                                    }
                                }
                            }

                            // Fallback to name extraction
                            if (!$vlanId) {
                                $vlanId = $this->extractVlanId($name);
                            }

                            if ($vlanId) {
                                $vlans[] = [
                                    'vlan_vlan' => $vlanId,
                                    'vlan_name' => $name,
                                    'vlan_type' => 'distributed_portgroup',
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        Log::debug("VCenterSoapClient: Could not fetch DVPortGroup properties: {$e->getMessage()}");
                    }
                }
            }

            // Cleanup
            try {
                $this->client->__soapCall('DestroyView', [['_this' => $viewRef]]);
            } catch (\Exception $e) {
                // Ignore
            }
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: fetchDistributedPortGroupVlans error: {$e->getMessage()}");
        }

        return $vlans;
    }

    protected function fetchDistributedSwitchVlans(object $serviceContent): array
    {
        $vlans = [];

        try {
            // Create container view for DistributedVirtualSwitch objects
            $rootFolder = $serviceContent->rootFolder;
            $request = [
                '_this' => $serviceContent->viewManager,
                'container' => $rootFolder,
                'type' => ['VmwareDistributedVirtualSwitch'],
                'recursive' => true,
            ];

            $containerView = $this->client->__soapCall('CreateContainerView', [$request]);
            if (!isset($containerView->returnval)) {
                return [];
            }

            $viewRef = $containerView->returnval;
            $viewProperties = $this->retrieveProperties($serviceContent, $viewRef, 'ContainerView', ['view']);

            if (isset($viewProperties->view->ManagedObjectReference)) {
                $switches = $viewProperties->view->ManagedObjectReference;
                if (!is_array($switches)) {
                    $switches = [$switches];
                }

                foreach ($switches as $switchRef) {
                    try {
                        $switchProps = $this->retrieveProperties($serviceContent, $switchRef, 'VmwareDistributedVirtualSwitch', [
                            'name',
                            'config',
                        ]);

                        if ($switchProps && isset($switchProps->name)) {
                            $name = $switchProps->name;

                            // DVS itself doesn't have a VLAN, but we can log it for info
                            Log::debug("VCenterSoapClient: Found DVS: {$name}");
                        }
                    } catch (\Exception $e) {
                        Log::debug("VCenterSoapClient: Could not fetch DVS properties: {$e->getMessage()}");
                    }
                }
            }

            // Cleanup
            try {
                $this->client->__soapCall('DestroyView', [['_this' => $viewRef]]);
            } catch (\Exception $e) {
                // Ignore
            }
        } catch (\Exception $e) {
            Log::debug("VCenterSoapClient: fetchDistributedSwitchVlans error: {$e->getMessage()}");
        }

        return $vlans;
    }

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
            Log::error("VCenterSoapClient: retrieveProperties failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Extract VLAN ID from name
     * Supports patterns like: "VLAN 100", "vlan-100", "Network-100", "100"
     */
    protected function extractVlanId(string $name): ?int
    {
        // Try to match common VLAN naming patterns
        if (preg_match('/vlan[_\s-]*(\d+)/i', $name, $matches)) {
            return (int) $matches[1];
        }

        // Try to match "Network-100" or similar
        if (preg_match('/network[_\s-]*(\d+)/i', $name, $matches)) {
            return (int) $matches[1];
        }

        // Try to match just numbers at the end
        if (preg_match('/(\d+)$/', $name, $matches)) {
            $vlanId = (int) $matches[1];
            // Only accept if it's a valid VLAN ID (1-4094)
            if ($vlanId >= 1 && $vlanId <= 4094) {
                return $vlanId;
            }
        }

        return null;
    }

    /**
     * Check if this client supports the given device
     */
    public function supports(Device $device): bool
    {
        // This client only supports devices with vcenter_soap template
        $apiConfig = $device->apiConfig;
        return $apiConfig && $apiConfig->template && $apiConfig->template->key === 'vcenter_soap';
    }

    /**
     * Test connection to vCenter SOAP API
     */
    public function testConnection(): array
    {
        try {
            if (!$this->login()) {
                return [
                    'success' => false,
                    'message' => 'Failed to login to vCenter SOAP API',
                ];
            }

            $serviceContent = $this->getServiceContent();
            if (!$serviceContent) {
                return [
                    'success' => false,
                    'message' => 'Failed to retrieve service content',
                ];
            }

            $this->logout();

            return [
                'success' => true,
                'message' => 'Successfully connected to vCenter SOAP API',
                'data' => [
                    'api_version' => $serviceContent->about->apiVersion ?? 'unknown',
                    'product' => $serviceContent->about->fullName ?? 'VMware vCenter',
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    // Placeholder methods required by DeviceApiClientInterface
    public function get(string $endpoint, array $params = []): array
    {
        // For connection testing, return success if we can retrieve service content
        if ($endpoint === 'test' || $endpoint === '/test') {
            $result = $this->testConnection();
            if ($result['success']) {
                return $result;
            }
            throw new \Exception($result['message']);
        }

        // SOAP client doesn't support REST endpoints - return empty array
        // This allows DeviceApiPersistor methods to gracefully handle SOAP-only clients
        Log::debug("VCenterSoapClient: get($endpoint) not supported via SOAP, returning empty array");
        return [];
    }

    public function post(string $endpoint, array $data = []): array
    {
        throw new \Exception("VCenterSoapClient: Direct post() not supported");
    }

    // Required interface methods - not used for SOAP client
    public function capabilities(): array
    {
        return ['vlans'];
    }

    public function fetchSensors(Device $device): array
    {
        return [];
    }

    public function fetchPorts(Device $device): array
    {
        return [];
    }

    public function fetchPortsStatistics(Device $device): array
    {
        return [];
    }

    public function fetchIpv4Addresses(Device $device): array
    {
        return [];
    }

    public function fetchIpv6Addresses(Device $device): array
    {
        return [];
    }

    public function fetchInventory(Device $device): array
    {
        return [];
    }

    public function fetchProcessors(Device $device): array
    {
        return [];
    }

    public function fetchMempools(Device $device): array
    {
        return [];
    }

    public function fetchStorage(Device $device): array
    {
        return [];
    }

    public function fetchVms(Device $device): array
    {
        return [];
    }

    public function fetchNeighbors(Device $device): array
    {
        return [];
    }

    public function fetchDeviceInfo(Device $device): array
    {
        return [];
    }

    public function fetchTransceivers(Device $device): array
    {
        return [];
    }

    public function isReachable(): bool
    {
        try {
            return $this->login();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        try {
            $serviceContent = $this->getServiceContent();
            if ($serviceContent && isset($serviceContent->about)) {
                return [
                    'api_type' => 'vSphere SOAP',
                    'api_version' => $serviceContent->about->apiVersion ?? 'unknown',
                    'product' => $serviceContent->about->fullName ?? 'VMware vCenter',
                    'vendor' => $serviceContent->about->vendor ?? 'VMware',
                ];
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return [
            'api_type' => 'vSphere SOAP',
            'api_version' => 'unknown',
        ];
    }
}
