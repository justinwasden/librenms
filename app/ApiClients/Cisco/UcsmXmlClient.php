<?php

namespace App\ApiClients\Cisco;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\TestableDevice;
use App\Models\Device;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;
use LibreNMS\Util\IP;
use LibreNMS\Util\Normalizers\UcsmXmlNormalizer;

/**
 * Cisco UCS Manager XML API Client
 *
 * Implements XML-over-HTTP API for Cisco UCS Manager
 * Authentication via aaaLogin with cookie-based sessions
 */
class UcsmXmlClient implements DeviceApiClientInterface
{
    protected Client $client;
    protected Device|TestableDevice $device;
    protected ?string $cookie = null;
    protected ?int $sessionTimeout = null;
    protected ?int $lastActivity = null;

    private const UCSM_FI_ID_PATTERN = '/\b(?:fi|fabric|switch)[-_ ]*([ab])\b/i';

    public function __construct(Device|TestableDevice $device)
    {
        $this->device = $device;

        // Read from device attributes (migrated from legacy table-based config)
        $baseUrl = $device->getAttrib('api_base_url');

        if (!$baseUrl) {
            throw new \Exception("No API configuration found for device {$device->device_id}");
        }

        $verifySSL = (bool) $device->getAttrib('api_verify_ssl', false);
        $this->sessionTimeout = (int) $device->getAttrib('api_credential_session_timeout', 600);

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'verify' => $verifySSL,
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

        // Read credentials from device attributes (decrypt if encrypted)
        $username = DeviceApiSettings::getCredential($this->device, 'api_credential_username') ?? '';
        $password = DeviceApiSettings::getCredential($this->device, 'api_credential_password') ?? '';

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
     * Determine which FI to collect data for based on device target and UCSM FI attributes.
     */
    protected function resolveTargetFiId(array $fiList): ?string
    {
        $target = trim((string) $this->device->pollerTarget());
        $hostname = trim((string) ($this->device->hostname ?? ''));

        foreach ($fiList as $fi) {
            $attrs = $fi['@attributes'] ?? $fi;
            $id = (string) ($attrs['id'] ?? '');
            $oob = (string) ($attrs['oobIfIp'] ?? '');
            $inband = (string) ($attrs['inbandIfIp'] ?? '');
            $name = (string) ($attrs['name'] ?? '');
            $dn = (string) ($attrs['dn'] ?? '');

            foreach ([$oob, $inband, $name, $dn] as $value) {
                if ($value !== '' && ($value === $target || $value === $hostname)) {
                    return $id !== '' ? $id : null;
                }
            }
        }

        foreach ([$target, $hostname] as $value) {
            if ($value === '' || IP::isValid($value)) {
                continue;
            }

            if (preg_match(self::UCSM_FI_ID_PATTERN, $value, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        return null;
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
     * Fetch Fibre Channel physical ports
     */
    public function fetchFibreChannelPorts(Device $device): array
    {
        $data = $this->resolveClass('fcPIo');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch server-facing Ethernet ports (unified ports on IOM)
     */
    public function fetchServerEthernetPorts(Device $device): array
    {
        $data = $this->resolveClass('etherServerIntFIo');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch Ethernet port channel (aggregation) information
     */
    public function fetchEthernetPortChannels(Device $device): array
    {
        $data = $this->resolveClass('fabricEthLanPc');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch backplane port statistics (IOM to blade connectivity)
     */
    public function fetchBackplanePorts(Device $device): array
    {
        $data = $this->resolveClass('fabricDceSwSrvPc');
        return $data ? ['data' => $data] : [];
    }

    /**
     * Fetch detailed Ethernet port statistics with traffic counters
     */
    public function fetchEthernetTrafficStats(Device $device): array
    {
        // Get comprehensive port stats including bytes, packets, errors
        $rxStats = $this->resolveClass('etherRxStats');
        $txStats = $this->resolveClass('etherTxStats');
        $errStats = $this->resolveClass('etherErrStats');
        $lossStats = $this->resolveClass('etherLossStats');

        return [
            'data' => [
                'rx_stats' => $rxStats ?? [],
                'tx_stats' => $txStats ?? [],
                'error_stats' => $errStats ?? [],
                'loss_stats' => $lossStats ?? [],
            ],
        ];
    }

    /**
     * Fetch port statistics (required by RestPortsStatisticsModule)
     * Alias for fetchEthernetTrafficStats
     */
    public function fetchPortsStatistics(Device|TestableDevice $device): array
    {
        return [];
    }

    /**
     * Fetch Fibre Channel traffic statistics
     */
    public function fetchFibreChannelTrafficStats(Device $device): array
    {
        $rxStats = $this->resolveClass('fcStats');
        $errStats = $this->resolveClass('fcErrStats');

        return [
            'data' => [
                'fc_stats' => $rxStats ?? [],
                'fc_err_stats' => $errStats ?? [],
            ],
        ];
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
    public function supports(Device|TestableDevice $device): bool
    {
        // Check if device has UCSM API config (from device attributes)
        $templateKey = $device->getAttrib('api_template_key');
        return in_array($templateKey, ['cisco_ucsm', 'cisco_ucsm_xml']);
    }

    public function capabilities(): array
    {
        return ['inventory', 'sensors', 'processors', 'mempools', 'ports', 'ipv4', 'vlans'];
    }

    /**
     * Fetch sensors from various UCSM sources
     */
    public function fetchSensors(Device|TestableDevice $device): array
    {
        $sensors = [];

        try {
            $fiData = $this->fetchFabricInterconnects($device);
            if (!empty($fiData)) {
                $normalized = UcsmXmlNormalizer::normalizeFabricInterconnects($device, $fiData);
                if (!empty($normalized['sensors'])) {
                    $sensors = array_merge($sensors, $normalized['sensors']);
                }
            }

            $topSystemData = $this->fetchTopSystem($device);
            if (!empty($topSystemData)) {
                $normalized = UcsmXmlNormalizer::normalizeTopSystem($device, $topSystemData);
                if (!empty($normalized['sensors'])) {
                    $sensors = array_merge($sensors, $normalized['sensors']);
                }
            }

            $faultData = $this->fetchFaults($device);
            if (!empty($faultData)) {
                $normalized = UcsmXmlNormalizer::normalizeFaults($device, $faultData);
                if (!empty($normalized['sensors'])) {
                    $sensors = array_merge($sensors, $normalized['sensors']);
                }
            }
        } catch (\Throwable $e) {
            Log::error('UCSM fetchSensors failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $sensors;
    }

    /**
     * Fetch ports from UCSM fabric interconnects
     */
    public function fetchPorts(Device|TestableDevice $device): array
    {
        $ports = [];

        try {
            $fis = $this->resolveClass('networkElement');
            $fiItems = $fis['outConfigs']['networkElement'] ?? [];
            $fiItems = isset($fiItems['@attributes']) ? [$fiItems] : $fiItems;
            $targetFiId = $this->resolveTargetFiId($fiItems);

            if (!$targetFiId) {
                Log::debug('UCSM: No matching FI for device target, skipping ports', [
                    'device_id' => $this->device->device_id,
                ]);
                return [];
            }

            // Get Ethernet ports from fabric interconnects
            $etherPorts = $this->resolveClass('etherPIo');
            if (!empty($etherPorts['outConfigs']['etherPIo'])) {
                $items = $etherPorts['outConfigs']['etherPIo'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                $ifIndex = 1;
                foreach ($items as $port) {
                    $attrs = $port['@attributes'] ?? $port;
                    $switchId = strtoupper((string) ($attrs['switchId'] ?? ''));
                    if ($switchId !== $targetFiId) {
                        continue;
                    }

                    $dn = $attrs['dn'] ?? '';
                    $portId = $attrs['portId'] ?? $ifIndex;
                    $slotId = $attrs['slotId'] ?? '0';

                    $adminState = strtolower($attrs['adminState'] ?? 'enabled');
                    $operState = strtolower($attrs['operState'] ?? 'down');

                    $ports[] = [
                        'ifIndex' => $ifIndex++,
                        'ifName' => "Eth{$slotId}/{$portId}",
                        'ifDescr' => $dn,
                        'ifType' => 'ethernetCsmacd',
                        'ifOperStatus' => $operState === 'up' ? 'up' : 'down',
                        'ifAdminStatus' => $adminState === 'enabled' ? 'up' : 'down',
                        'ifSpeed' => $this->parseSpeed($attrs['operSpeed'] ?? ''),
                        'ifMtu' => 1500,
                        'ifPhysAddress' => $attrs['mac'] ?? '',
                        'ifAlias' => $attrs['name'] ?? '',
                    ];
                }
            }

            // Get server-facing Ethernet ports
            $serverPorts = $this->resolveClass('etherServerIntFIo');
            if (!empty($serverPorts['outConfigs']['etherServerIntFIo'])) {
                $items = $serverPorts['outConfigs']['etherServerIntFIo'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                $ifIndex = count($ports) + 1;
                foreach ($items as $port) {
                    $attrs = $port['@attributes'] ?? $port;
                    $switchId = strtoupper((string) ($attrs['switchId'] ?? ''));
                    if ($switchId !== '' && $switchId !== $targetFiId) {
                        continue;
                    }

                    $dn = $attrs['dn'] ?? '';
                    $portId = $attrs['portId'] ?? $ifIndex;

                    $adminState = strtolower($attrs['adminState'] ?? 'enabled');
                    $operState = strtolower($attrs['operState'] ?? 'down');

                    $ports[] = [
                        'ifIndex' => $ifIndex++,
                        'ifName' => "Server-{$portId}",
                        'ifDescr' => $dn,
                        'ifType' => 'ethernetCsmacd',
                        'ifOperStatus' => $operState === 'up' ? 'up' : 'down',
                        'ifAdminStatus' => $adminState === 'enabled' ? 'up' : 'down',
                        'ifSpeed' => $this->parseSpeed($attrs['operSpeed'] ?? ''),
                        'ifMtu' => 1500,
                        'ifPhysAddress' => $attrs['mac'] ?? '',
                        'ifAlias' => 'Server Port',
                    ];
                }
            }

            Log::debug('UCSM: Fetched ports', [
                'device_id' => $this->device->device_id,
                'count' => count($ports),
            ]);

        } catch (\Throwable $e) {
            Log::error('UCSM fetchPorts failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $ports;
    }

    /**
     * Fetch processors from UCSM blades
     */
    public function fetchProcessors(Device|TestableDevice $device): array
    {
        try {
            $stats = $this->fetchSwitchStats($device);
            if (empty($stats)) {
                return [];
            }

            $normalized = UcsmXmlNormalizer::normalizeSwitchStats($device, $stats);
            return $normalized['processors'] ?? [];
        } catch (\Throwable $e) {
            Log::error('UCSM fetchProcessors failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Fetch memory pools from UCSM blades
     */
    public function fetchMempools(Device|TestableDevice $device): array
    {
        try {
            $stats = $this->fetchSwitchStats($device);
            if (empty($stats)) {
                return [];
            }

            $normalized = UcsmXmlNormalizer::normalizeSwitchStats($device, $stats);
            return $normalized['mempools'] ?? [];
        } catch (\Throwable $e) {
            Log::error('UCSM fetchMempools failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Fetch storage from UCSM (local disks on blades)
     */
    public function fetchStorage(Device|TestableDevice $device): array
    {
        return [];
    }

    /**
     * Fetch inventory from UCSM
     */
    public function fetchInventory(Device|TestableDevice $device): array
    {
        $inventory = [];

        try {
            $entIndex = 1;

            // Fabric Interconnects
            $fis = $this->resolveClass('networkElement');
            if (!empty($fis['outConfigs']['networkElement'])) {
                $items = $fis['outConfigs']['networkElement'];
                $items = isset($items['@attributes']) ? [$items] : $items;
                $targetFiId = $this->resolveTargetFiId($items);

                if (!$targetFiId) {
                    Log::debug('UCSM: No matching FI for device target, skipping inventory', [
                        'device_id' => $this->device->device_id,
                    ]);
                    return [];
                }

                foreach ($items as $fi) {
                    $attrs = $fi['@attributes'] ?? $fi;
                    $fiId = strtoupper((string) ($attrs['id'] ?? ''));
                    if ($fiId !== $targetFiId) {
                        continue;
                    }

                    $inventory[] = [
                        'entPhysicalIndex' => $entIndex++,
                        'entPhysicalDescr' => 'UCS Fabric Interconnect',
                        'entPhysicalClass' => 'chassis',
                        'entPhysicalName' => $attrs['dn'] ?? 'FI',
                        'entPhysicalModelName' => $attrs['model'] ?? '',
                        'entPhysicalSerialNum' => $attrs['serial'] ?? '',
                        'entPhysicalContainedIn' => 0,
                        'entPhysicalMfgName' => 'Cisco',
                    ];
                }
            }

            Log::debug('UCSM: Fetched inventory', [
                'device_id' => $this->device->device_id,
                'count' => count($inventory),
            ]);

        } catch (\Throwable $e) {
            Log::error('UCSM fetchInventory failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $inventory;
    }

    /**
     * Fetch VLANs configured in UCSM
     */
    public function fetchVlans(Device|TestableDevice $device): array
    {
        $vlans = [];

        try {
            $data = $this->resolveClass('fabricVlan');
            $items = $data['outConfigs']['fabricVlan'] ?? [];
            $items = isset($items['@attributes']) ? [$items] : $items;

            foreach ($items as $vlan) {
                $attrs = $vlan['@attributes'] ?? $vlan;
                $vlanIdRaw = $attrs['id'] ?? null;
                if ($vlanIdRaw === null || $vlanIdRaw === '') {
                    continue;
                }

                $vlanId = is_numeric($vlanIdRaw) ? (int) $vlanIdRaw : (int) preg_replace('/\D+/', '', (string) $vlanIdRaw);
                if ($vlanId <= 0) {
                    continue;
                }

                $vlans[] = [
                    'vlan_vlan' => $vlanId,
                    'vlan_domain' => 1,
                    'vlan_name' => $attrs['name'] ?? "VLAN{$vlanId}",
                    'vlan_type' => 'ethernet',
                ];
            }
        } catch (\Throwable $e) {
            Log::error('UCSM fetchVlans failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $vlans;
    }

    /**
     * Fetch IPv4 addresses from UCSM management interfaces
     */
    public function fetchIpv4Addresses(Device|TestableDevice $device): array
    {
        $addresses = [];

        try {
            // Get management controller IPs
            $mgmt = $this->resolveClass('mgmtController');
            if (!empty($mgmt['outConfigs']['mgmtController'])) {
                $items = $mgmt['outConfigs']['mgmtController'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $ctrl) {
                    $attrs = $ctrl['@attributes'] ?? $ctrl;
                    $ip = $attrs['ipAddr'] ?? null;

                    if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $addresses[] = [
                            'ipv4_address' => $ip,
                            'ipv4_prefixlen' => 24,
                            'ifName' => 'mgmt0',
                        ];
                    }
                }
            }

            Log::debug('UCSM: Fetched IPv4 addresses', [
                'device_id' => $this->device->device_id,
                'count' => count($addresses),
            ]);

        } catch (\Throwable $e) {
            Log::error('UCSM fetchIpv4Addresses failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $addresses;
    }

    /**
     * Fetch transceivers - UCSM doesn't expose transceiver details
     */
    public function fetchTransceivers(Device|TestableDevice $device): array
    {
        return [];
    }

    /**
     * Parse speed string to bps
     */
    protected function parseSpeed(string $speed): int
    {
        $speed = strtolower($speed);

        if (preg_match('/(\d+)\s*gbps/', $speed, $m)) {
            return (int) $m[1] * 1000000000;
        }
        if (preg_match('/(\d+)\s*mbps/', $speed, $m)) {
            return (int) $m[1] * 1000000;
        }
        if ($speed === '10g') {
            return 10000000000;
        }
        if ($speed === '40g') {
            return 40000000000;
        }
        if ($speed === '100g') {
            return 100000000000;
        }

        return 0;
    }

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

    public function fetchVms(Device|TestableDevice $device): array
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
