<?php

namespace App\ApiClients\Cisco;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Cisco UCS Manager XML API Client
 *
 * Implements XML-over-HTTP API for Cisco UCS Manager
 * Authentication via aaaLogin with cookie-based sessions
 */
class UcsmXmlClient implements DeviceApiClientInterface
{
    protected Client $client;
    protected Device $device;
    protected ?string $cookie = null;
    protected ?int $sessionTimeout = null;
    protected ?int $lastActivity = null;

    public function __construct(Device $device)
    {
        $this->device = $device;
        $apiConfig = $device->apiConfig;

        if (!$apiConfig) {
            throw new \Exception("No API configuration found for device {$device->device_id}");
        }

        $baseUrl = $apiConfig->base_url ?? 'https://' . $device->hostname;
        $this->sessionTimeout = $apiConfig->getValue('session_timeout') ?? 600;

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'verify' => $apiConfig->verify_ssl ?? false,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/xml',
            ],
        ]);
    }

    /**
     * Login to UCSM and get session cookie
     */
    protected function login(): bool
    {
        // Check if we have a valid session
        if ($this->cookie && $this->lastActivity) {
            $elapsed = time() - $this->lastActivity;
            if ($elapsed < ($this->sessionTimeout - 60)) { // Refresh 60 seconds before timeout
                return true;
            }
        }

        $apiConfig = $this->device->apiConfig;
        $username = $apiConfig->getValue('username') ?? '';
        $password = $apiConfig->getValue('password') ?? '';

        $loginXml = sprintf(
            '<aaaLogin inName="%s" inPassword="%s"></aaaLogin>',
            htmlspecialchars($username, ENT_XML1, 'UTF-8'),
            htmlspecialchars($password, ENT_XML1, 'UTF-8')
        );

        try {
            $response = $this->client->post('/nuova', [
                'body' => $loginXml,
            ]);

            $xml = simplexml_load_string($response->getBody()->getContents());

            if (isset($xml['outCookie'])) {
                $this->cookie = (string) $xml['outCookie'];
                $this->lastActivity = time();
                Log::debug("UCSM XML API login successful for device {$this->device->device_id}");
                return true;
            }

            if (isset($xml['errorCode'])) {
                Log::error("UCSM XML API login failed", [
                    'device_id' => $this->device->device_id,
                    'error_code' => (string) $xml['errorCode'],
                    'error_descr' => (string) $xml['errorDescr'],
                ]);
            }

            return false;

        } catch (GuzzleException $e) {
            Log::error("UCSM XML API login exception", [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Logout and invalidate session cookie
     */
    protected function logout(): void
    {
        if (!$this->cookie) {
            return;
        }

        $logoutXml = sprintf('<aaaLogout inCookie="%s"></aaaLogout>', htmlspecialchars($this->cookie, ENT_XML1, 'UTF-8'));

        try {
            $this->client->post('/nuova', ['body' => $logoutXml]);
        } catch (\Exception $e) {
            Log::debug("UCSM logout failed (non-critical): {$e->getMessage()}");
        }

        $this->cookie = null;
        $this->lastActivity = null;
    }

    /**
     * Execute a configResolveClass query
     */
    protected function resolveClass(string $classId, bool $inHierarchical = false): ?array
    {
        if (!$this->login()) {
            return null;
        }

        $hierarchical = $inHierarchical ? 'true' : 'false';
        $queryXml = sprintf(
            '<configResolveClass cookie="%s" classId="%s" inHierarchical="%s"></configResolveClass>',
            htmlspecialchars($this->cookie, ENT_XML1, 'UTF-8'),
            htmlspecialchars($classId, ENT_XML1, 'UTF-8'),
            $hierarchical
        );

        try {
            $response = $this->client->post('/nuova', ['body' => $queryXml]);
            $this->lastActivity = time();

            $xml = simplexml_load_string($response->getBody()->getContents());

            if (isset($xml['errorCode']) && (string) $xml['errorCode'] !== '0') {
                Log::warning("UCSM configResolveClass failed", [
                    'device_id' => $this->device->device_id,
                    'classId' => $classId,
                    'error_code' => (string) $xml['errorCode'],
                    'error_descr' => (string) $xml['errorDescr'],
                ]);
                return null;
            }

            // Convert XML to array
            return $this->xmlToArray($xml);

        } catch (\Exception $e) {
            Log::error("UCSM configResolveClass exception", [
                'device_id' => $this->device->device_id,
                'classId' => $classId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convert SimpleXML to array
     */
    protected function xmlToArray(\SimpleXMLElement $xml): array
    {
        $json = json_encode($xml);
        return json_decode($json, true) ?? [];
    }

    /**
     * Fetch chassis information
     */
    public function fetchChassis(Device $device): array
    {
        $data = $this->resolveClass('equipmentChassis');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch compute blades
     */
    public function fetchBlades(Device $device): array
    {
        $data = $this->resolveClass('computeBlade');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch fabric interconnects
     */
    public function fetchFabricInterconnects(Device $device): array
    {
        $data = $this->resolveClass('networkElement');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch network adapters
     */
    public function fetchAdapters(Device $device): array
    {
        $data = $this->resolveClass('adaptorUnit');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch Ethernet ports
     */
    public function fetchEthernetPorts(Device $device): array
    {
        $data = $this->resolveClass('fabricEthLanPc');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch power supplies
     */
    public function fetchPowerSupplies(Device $device): array
    {
        $data = $this->resolveClass('equipmentPsu');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch fans
     */
    public function fetchFans(Device $device): array
    {
        $data = $this->resolveClass('equipmentFan');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch faults
     */
    public function fetchFaults(Device $device): array
    {
        $data = $this->resolveClass('faultInst');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch processor statistics
     */
    public function fetchProcessorStats(Device $device): array
    {
        $data = $this->resolveClass('processorEnvStats');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch memory statistics
     */
    public function fetchMemoryStats(Device $device): array
    {
        $data = $this->resolveClass('memoryUnitEnvStats');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch ethernet port statistics
     */
    public function fetchEthernetPortStats(Device $device): array
    {
        $data = $this->resolveClass('etherRxStats');
        $txData = $this->resolveClass('etherTxStats');

        return [
            'rx_stats' => $data ?? [],
            'tx_stats' => $txData ?? [],
        ];
    }

    /**
     * Fetch management controller info (for HA status)
     */
    public function fetchManagementController(Device $device): array
    {
        $data = $this->resolveClass('mgmtController');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch switch (FI) system statistics
     */
    public function fetchSwitchStats(Device $device): array
    {
        $data = $this->resolveClass('swSystemStats');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch management entity (for HA/leadership status)
     */
    public function fetchManagementEntity(Device $device): array
    {
        $data = $this->resolveClass('mgmtEntity');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch top system (for UCS domain/cluster name)
     */
    public function fetchTopSystem(Device $device): array
    {
        $data = $this->resolveClass('topSystem');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch adapter vNIC statistics (virtual network adapter stats on blades)
     */
    public function fetchAdapterVnicStats(Device $device): array
    {
        $data = $this->resolveClass('adaptorVnicStats');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch Ethernet error statistics
     */
    public function fetchEthernetErrorStats(Device $device): array
    {
        $data = $this->resolveClass('etherErrStats');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch Ethernet loss statistics
     */
    public function fetchEthernetLossStats(Device $device): array
    {
        $data = $this->resolveClass('etherLossStats');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch FI Ethernet port information
     */
    public function fetchFabricEthernetPorts(Device $device): array
    {
        $data = $this->resolveClass('etherPIo');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch temperature statistics
     */
    public function fetchTemperatureStats(Device $device): array
    {
        $data = $this->resolveClass('equipmentChassisStats');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Test connection
     */
    public function testConnection(): bool
    {
        try {
            $success = $this->login();
            if ($success) {
                $this->logout();
            }
            return $success;
        } catch (\Exception $e) {
            Log::error("UCSM testConnection failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * DeviceApiClientInterface implementations (fallback to empty arrays)
     */
    public function supports(Device $device): bool
    {
        // Check if device has UCSM API config
        return $device->apiConfig && $device->apiConfig->template?->key === 'cisco_ucsm_xml';
    }

    public function capabilities(): array
    {
        return ['inventory', 'sensors', 'processors', 'mempools', 'device_info'];
    }

    public function fetchPorts(Device $device): array { return []; }
    public function fetchProcessors(Device $device): array { return []; }
    public function fetchMempools(Device $device): array { return []; }
    public function fetchStorage(Device $device): array { return []; }
    public function fetchSensors(Device $device): array { return []; }
    public function fetchInventory(Device $device): array { return []; }
    public function fetchPortsStatistics(Device $device): array { return []; }
    public function fetchIpv4Addresses(Device $device): array { return []; }
    public function fetchTransceivers(Device $device): array { return []; }

    /**
     * HTTP transport methods - not used for XML API
     */
    public function get(string $path, array $query = []): array
    {
        throw new \RuntimeException('GET method not supported for UCSM XML API - use specific fetch methods');
    }

    public function post(string $path, array $body = []): array
    {
        throw new \RuntimeException('POST method not supported for UCSM XML API - use specific fetch methods');
    }

    public function isReachable(): bool
    {
        return $this->testConnection();
    }

    public function getApiInfo(): array
    {
        return [
            'vendor' => 'Cisco',
            'api_type' => 'UCSM XML API',
            'version' => 'unknown',
        ];
    }

    public function fetchVms(Device $device): array
    {
        // UCSM manages physical infrastructure, not VMs directly
        // VMs would be managed by VMware/Hyper-V on the blades
        return [];
    }

    public function __destruct()
    {
        $this->logout();
    }
}
