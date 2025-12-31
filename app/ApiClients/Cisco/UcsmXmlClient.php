<?php

namespace App\ApiClients\Cisco;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;

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
    public function fetchPortsStatistics(Device $device): array
    {
        return $this->fetchEthernetTrafficStats($device);
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
    public function supports(Device $device): bool
    {
        // Check if device has UCSM API config (from device attributes)
        $templateKey = $device->getAttrib('api_template_key');
        return in_array($templateKey, ['cisco_ucsm', 'cisco_ucsm_xml']);
    }

    public function capabilities(): array
    {
        return ['inventory', 'sensors', 'processors', 'mempools', 'ports', 'device_info', 'ports_stats'];
    }

    /**
     * Fetch sensors from various UCSM sources
     */
    public function fetchSensors(Device $device): array
    {
        $sensors = [];

        try {
            // Temperature sensors from chassis stats
            $chassisStats = $this->resolveClass('equipmentChassisStats');
            if (!empty($chassisStats['outConfigs']['equipmentChassisStats'])) {
                $items = $chassisStats['outConfigs']['equipmentChassisStats'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $idx => $stat) {
                    $attrs = $stat['@attributes'] ?? $stat;
                    $dn = $attrs['dn'] ?? "chassis-{$idx}";

                    // Inlet temperature
                    if (isset($attrs['inletTemp'])) {
                        $sensors[] = [
                            'sensor_class' => 'temperature',
                            'sensor_type' => 'ucsm-chassis',
                            'sensor_descr' => "Chassis Inlet Temperature ({$dn})",
                            'sensor_index' => "chassis-inlet-{$idx}",
                            'sensor_current' => (float) $attrs['inletTemp'],
                            'sensor_limit' => 45,
                            'sensor_limit_low' => 0,
                        ];
                    }

                    // Input power
                    if (isset($attrs['inputPower'])) {
                        $sensors[] = [
                            'sensor_class' => 'power',
                            'sensor_type' => 'ucsm-chassis',
                            'sensor_descr' => "Chassis Input Power ({$dn})",
                            'sensor_index' => "chassis-power-{$idx}",
                            'sensor_current' => (float) $attrs['inputPower'],
                        ];
                    }
                }
            }

            // Processor env stats
            $procStats = $this->resolveClass('processorEnvStats');
            if (!empty($procStats['outConfigs']['processorEnvStats'])) {
                $items = $procStats['outConfigs']['processorEnvStats'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $idx => $stat) {
                    $attrs = $stat['@attributes'] ?? $stat;
                    $dn = $attrs['dn'] ?? "proc-{$idx}";

                    if (isset($attrs['temperature'])) {
                        $sensors[] = [
                            'sensor_class' => 'temperature',
                            'sensor_type' => 'ucsm-processor',
                            'sensor_descr' => "Processor Temperature ({$dn})",
                            'sensor_index' => "proc-temp-{$idx}",
                            'sensor_current' => (float) $attrs['temperature'],
                            'sensor_limit' => 100,
                            'sensor_limit_low' => 0,
                        ];
                    }
                }
            }

            // Memory env stats
            $memStats = $this->resolveClass('memoryUnitEnvStats');
            if (!empty($memStats['outConfigs']['memoryUnitEnvStats'])) {
                $items = $memStats['outConfigs']['memoryUnitEnvStats'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $idx => $stat) {
                    $attrs = $stat['@attributes'] ?? $stat;
                    $dn = $attrs['dn'] ?? "dimm-{$idx}";

                    if (isset($attrs['temperature'])) {
                        $sensors[] = [
                            'sensor_class' => 'temperature',
                            'sensor_type' => 'ucsm-memory',
                            'sensor_descr' => "Memory Temperature ({$dn})",
                            'sensor_index' => "mem-temp-{$idx}",
                            'sensor_current' => (float) $attrs['temperature'],
                            'sensor_limit' => 85,
                            'sensor_limit_low' => 0,
                        ];
                    }
                }
            }

            // Fan status
            $fans = $this->resolveClass('equipmentFan');
            if (!empty($fans['outConfigs']['equipmentFan'])) {
                $items = $fans['outConfigs']['equipmentFan'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $idx => $fan) {
                    $attrs = $fan['@attributes'] ?? $fan;
                    $dn = $attrs['dn'] ?? "fan-{$idx}";
                    $operState = strtolower($attrs['operState'] ?? 'unknown');

                    $stateMap = [
                        'operable' => 1,
                        'ok' => 1,
                        'inoperable' => 0,
                        'degraded' => 2,
                        'removed' => 3,
                    ];

                    $sensors[] = [
                        'sensor_class' => 'state',
                        'sensor_type' => 'ucsm-fan',
                        'sensor_descr' => "Fan State ({$dn})",
                        'sensor_index' => "fan-state-{$idx}",
                        'sensor_current' => $stateMap[$operState] ?? 4,
                        'states' => [
                            ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'inoperable'],
                            ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'operable'],
                            ['value' => 2, 'generic' => 1, 'graph' => 0, 'descr' => 'degraded'],
                            ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'removed'],
                            ['value' => 4, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                        ],
                    ];
                }
            }

            // PSU status
            $psus = $this->resolveClass('equipmentPsu');
            if (!empty($psus['outConfigs']['equipmentPsu'])) {
                $items = $psus['outConfigs']['equipmentPsu'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $idx => $psu) {
                    $attrs = $psu['@attributes'] ?? $psu;
                    $dn = $attrs['dn'] ?? "psu-{$idx}";
                    $operState = strtolower($attrs['operState'] ?? 'unknown');

                    $stateMap = [
                        'operable' => 1,
                        'ok' => 1,
                        'inoperable' => 0,
                        'degraded' => 2,
                        'removed' => 3,
                    ];

                    $sensors[] = [
                        'sensor_class' => 'state',
                        'sensor_type' => 'ucsm-psu',
                        'sensor_descr' => "PSU State ({$dn})",
                        'sensor_index' => "psu-state-{$idx}",
                        'sensor_current' => $stateMap[$operState] ?? 4,
                        'states' => [
                            ['value' => 0, 'generic' => 2, 'graph' => 0, 'descr' => 'inoperable'],
                            ['value' => 1, 'generic' => 0, 'graph' => 1, 'descr' => 'operable'],
                            ['value' => 2, 'generic' => 1, 'graph' => 0, 'descr' => 'degraded'],
                            ['value' => 3, 'generic' => 3, 'graph' => 0, 'descr' => 'removed'],
                            ['value' => 4, 'generic' => 3, 'graph' => 0, 'descr' => 'unknown'],
                        ],
                    ];

                    // PSU wattage if available
                    if (isset($attrs['wattage']) && (int) $attrs['wattage'] > 0) {
                        $sensors[] = [
                            'sensor_class' => 'power',
                            'sensor_type' => 'ucsm-psu',
                            'sensor_descr' => "PSU Wattage ({$dn})",
                            'sensor_index' => "psu-watts-{$idx}",
                            'sensor_current' => (float) $attrs['wattage'],
                        ];
                    }
                }
            }

            Log::debug('UCSM: Fetched sensors', [
                'device_id' => $this->device->device_id,
                'count' => count($sensors),
            ]);

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
    public function fetchPorts(Device $device): array
    {
        $ports = [];

        try {
            // Get Ethernet ports from fabric interconnects
            $etherPorts = $this->resolveClass('etherPIo');
            if (!empty($etherPorts['outConfigs']['etherPIo'])) {
                $items = $etherPorts['outConfigs']['etherPIo'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                $ifIndex = 1;
                foreach ($items as $port) {
                    $attrs = $port['@attributes'] ?? $port;
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
    public function fetchProcessors(Device $device): array
    {
        $processors = [];

        try {
            $procStats = $this->resolveClass('processorUnit');
            if (!empty($procStats['outConfigs']['processorUnit'])) {
                $items = $procStats['outConfigs']['processorUnit'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $idx => $proc) {
                    $attrs = $proc['@attributes'] ?? $proc;
                    $dn = $attrs['dn'] ?? "processor-{$idx}";

                    $processors[] = [
                        'processor_index' => $idx,
                        'processor_type' => 'ucsm',
                        'processor_descr' => $attrs['model'] ?? "Processor {$idx}",
                        'processor_usage' => null, // UCSM doesn't provide real-time CPU usage
                    ];
                }
            }

            Log::debug('UCSM: Fetched processors', [
                'device_id' => $this->device->device_id,
                'count' => count($processors),
            ]);

        } catch (\Throwable $e) {
            Log::error('UCSM fetchProcessors failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $processors;
    }

    /**
     * Fetch memory pools from UCSM blades
     */
    public function fetchMempools(Device $device): array
    {
        $mempools = [];

        try {
            // Get memory units
            $memUnits = $this->resolveClass('memoryUnit');
            if (!empty($memUnits['outConfigs']['memoryUnit'])) {
                $items = $memUnits['outConfigs']['memoryUnit'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                // Aggregate memory by blade
                $bladeMemory = [];
                foreach ($items as $mem) {
                    $attrs = $mem['@attributes'] ?? $mem;
                    $dn = $attrs['dn'] ?? '';
                    $capacity = (int) ($attrs['capacity'] ?? 0);

                    // Extract blade DN from memory DN
                    if (preg_match('/(sys\/chassis-\d+\/blade-\d+)/', $dn, $m)) {
                        $bladeDn = $m[1];
                        $bladeMemory[$bladeDn] = ($bladeMemory[$bladeDn] ?? 0) + $capacity;
                    }
                }

                $idx = 0;
                foreach ($bladeMemory as $bladeDn => $totalMb) {
                    if ($totalMb > 0) {
                        $mempools[] = [
                            'mempool_index' => $idx++,
                            'mempool_type' => 'ucsm',
                            'mempool_descr' => "Memory ({$bladeDn})",
                            'mempool_total' => $totalMb * 1024 * 1024, // MB to bytes
                            'mempool_used' => 0, // UCSM doesn't provide used memory
                            'mempool_free' => $totalMb * 1024 * 1024,
                            'mempool_perc' => 0,
                        ];
                    }
                }
            }

            Log::debug('UCSM: Fetched mempools', [
                'device_id' => $this->device->device_id,
                'count' => count($mempools),
            ]);

        } catch (\Throwable $e) {
            Log::error('UCSM fetchMempools failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mempools;
    }

    /**
     * Fetch storage from UCSM (local disks on blades)
     */
    public function fetchStorage(Device $device): array
    {
        $storage = [];

        try {
            $disks = $this->resolveClass('storageLocalDisk');
            if (!empty($disks['outConfigs']['storageLocalDisk'])) {
                $items = $disks['outConfigs']['storageLocalDisk'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $idx => $disk) {
                    $attrs = $disk['@attributes'] ?? $disk;
                    $dn = $attrs['dn'] ?? "disk-{$idx}";
                    $size = (int) ($attrs['size'] ?? 0);

                    if ($size > 0) {
                        $storage[] = [
                            'storage_index' => $idx,
                            'storage_type' => 'hrStorageFixedDisk',
                            'storage_descr' => $attrs['model'] ?? "Local Disk ({$dn})",
                            'storage_size' => $size * 1024 * 1024, // MB to bytes
                            'storage_used' => 0,
                            'storage_free' => $size * 1024 * 1024,
                            'storage_perc' => 0,
                            'storage_units' => 1,
                        ];
                    }
                }
            }

            Log::debug('UCSM: Fetched storage', [
                'device_id' => $this->device->device_id,
                'count' => count($storage),
            ]);

        } catch (\Throwable $e) {
            Log::error('UCSM fetchStorage failed', [
                'device_id' => $this->device->device_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $storage;
    }

    /**
     * Fetch inventory from UCSM
     */
    public function fetchInventory(Device $device): array
    {
        $inventory = [];

        try {
            $entIndex = 1;

            // Chassis
            $chassis = $this->resolveClass('equipmentChassis');
            if (!empty($chassis['outConfigs']['equipmentChassis'])) {
                $items = $chassis['outConfigs']['equipmentChassis'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $ch) {
                    $attrs = $ch['@attributes'] ?? $ch;
                    $inventory[] = [
                        'entPhysicalIndex' => $entIndex++,
                        'entPhysicalDescr' => 'UCS Chassis',
                        'entPhysicalClass' => 'chassis',
                        'entPhysicalName' => $attrs['dn'] ?? 'Chassis',
                        'entPhysicalModelName' => $attrs['model'] ?? '',
                        'entPhysicalSerialNum' => $attrs['serial'] ?? '',
                        'entPhysicalContainedIn' => 0,
                        'entPhysicalMfgName' => 'Cisco',
                    ];
                }
            }

            // Blades
            $blades = $this->resolveClass('computeBlade');
            if (!empty($blades['outConfigs']['computeBlade'])) {
                $items = $blades['outConfigs']['computeBlade'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $blade) {
                    $attrs = $blade['@attributes'] ?? $blade;
                    $inventory[] = [
                        'entPhysicalIndex' => $entIndex++,
                        'entPhysicalDescr' => 'UCS Blade Server',
                        'entPhysicalClass' => 'module',
                        'entPhysicalName' => $attrs['dn'] ?? 'Blade',
                        'entPhysicalModelName' => $attrs['model'] ?? '',
                        'entPhysicalSerialNum' => $attrs['serial'] ?? '',
                        'entPhysicalContainedIn' => 1,
                        'entPhysicalMfgName' => 'Cisco',
                        'entPhysicalSoftwareRev' => $attrs['availableMemory'] ?? '',
                    ];
                }
            }

            // Fabric Interconnects
            $fis = $this->resolveClass('networkElement');
            if (!empty($fis['outConfigs']['networkElement'])) {
                $items = $fis['outConfigs']['networkElement'];
                $items = isset($items['@attributes']) ? [$items] : $items;

                foreach ($items as $fi) {
                    $attrs = $fi['@attributes'] ?? $fi;
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
     * Fetch IPv4 addresses from UCSM management interfaces
     */
    public function fetchIpv4Addresses(Device $device): array
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
    public function fetchTransceivers(Device $device): array
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
