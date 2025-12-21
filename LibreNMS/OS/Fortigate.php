<?php

/*
 * Fortigate.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       https://www.librenms.org
 * @copyright  2020 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\OS;

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Device\WirelessSensor;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Discovery\SensorDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessApCountDiscovery;
use LibreNMS\Interfaces\Discovery\Sensors\WirelessClientsDiscovery;
use LibreNMS\Interfaces\Polling\OSPolling;
use LibreNMS\OS\Shared\Fortinet;
use LibreNMS\OS\Traits\ApiPolling;
use LibreNMS\RRD\RrdDefinition;

class Fortigate extends Fortinet implements
    OSPolling,
    WirelessClientsDiscovery,
    WirelessApCountDiscovery,
    ProcessorDiscovery,
    SensorDiscovery
{
    use ApiPolling;

    public function discoverOS(Device $device): void
    {
        parent::discoverOS($device); // yaml

        $device->hardware = $device->hardware ?: $this->getHardwareName();
    }

    /**
     * Discover processors (via API if available, otherwise SNMP)
     */
    public function discoverProcessors()
    {
        // Try API discovery first
        if ($this->hasApiConfig()) {
            try {
                $client = DeviceApiClientFactory::make($this->getDevice());
                if ($client && in_array('processors', $client->capabilities())) {
                    $systemData = $client->get('/api/v2/monitor/system/resource');
                    $processors = $this->normalizeData('Fortinet\SystemUsage', $systemData);

                    if (!empty($processors)) {
                        return $processors;
                    }
                }
            } catch (\Exception $e) {
                Log::debug('FortiGate API processor discovery failed, falling back to SNMP', [
                    'device_id' => $this->getDevice()->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to SNMP (handled by parent class or return empty)
        return [];
    }

    /**
     * Discover sensors (via API if available, otherwise SNMP)
     */
    public function discoverSensors()
    {
        $sensors = [];

        // Try API discovery first
        if ($this->hasApiConfig()) {
            try {
                $client = DeviceApiClientFactory::make($this->getDevice());
                if ($client && in_array('sensors', $client->capabilities())) {
                    // System status sensors
                    $statusData = $client->get('/api/v2/monitor/system/status');
                    $statusSensors = $this->normalizeData('Fortinet\SystemStatus', $statusData);

                    if (!empty($statusSensors)) {
                        $sensors = array_merge($sensors, $statusSensors);
                    }

                    // Sensor info (hardware sensors)
                    try {
                        $sensorData = $client->get('/api/v2/monitor/system/sensor-info');
                        $hwSensors = $this->normalizeData('Fortinet\SensorInfo', $sensorData);

                        if (!empty($hwSensors)) {
                            $sensors = array_merge($sensors, $hwSensors);
                        }
                    } catch (\Exception $e) {
                        // Some FortiGate models don't support sensor-info endpoint
                        Log::debug('FortiGate sensor-info not available', [
                            'device_id' => $this->getDevice()->device_id,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::debug('FortiGate API sensor discovery failed, falling back to SNMP', [
                    'device_id' => $this->getDevice()->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sensors;
    }

    /**
     * Discover ports/interfaces (via API)
     */
    public function discoverPorts()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('ports', $client->capabilities())) {
                return [];
            }

            // Fetch interfaces
            $interfaceData = $client->get('/api/v2/monitor/system/interface');
            $ports = $this->normalizeData('Fortinet\Interfaces', $interfaceData);

            return $ports ?? [];
        } catch (\Exception $e) {
            Log::warning('FortiGate ports discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover VLANs (via API)
     */
    public function discoverVlans()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            $vlanData = $client->get('/api/v2/cmdb/system/interface');
            $vlans = $this->normalizeData('Fortinet\Vlans', $vlanData);

            return $vlans ?? [];
        } catch (\Exception $e) {
            Log::warning('FortiGate VLAN discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Discover IPv4 addresses (via API)
     */
    public function discoverIpv4()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !in_array('ipv4', $client->capabilities())) {
                return [];
            }

            $interfaceData = $client->get('/api/v2/monitor/system/interface');
            $ipv4 = $this->normalizeData('Fortinet\Ipv4', $interfaceData);

            return $ipv4 ?? [];
        } catch (\Exception $e) {
            Log::warning('FortiGate IPv4 discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function pollOS(DataStorageInterface $datastore): void
    {
        $sessions = snmp_get($this->getDeviceArray(), 'FORTINET-FORTIGATE-MIB::fgSysSesCount.0', '-Ovq');
        if (is_numeric($sessions)) {
            $rrd_def = RrdDefinition::make()->addDataset('sessions', 'GAUGE', 0, 3000000);

            Log::info("Sessions: $sessions");
            $fields = [
                'sessions' => $sessions,
            ];

            $tags = ['rrd_def' => $rrd_def];
            $datastore->put($this->getDeviceArray(), 'fortigate_sessions', $tags, $fields);
            $this->enableGraph('fortigate_sessions');
        }

        $cpu_usage = snmp_get($this->getDeviceArray(), 'FORTINET-FORTIGATE-MIB::fgSysCpuUsage.0', '-Ovq');
        if (is_numeric($cpu_usage)) {
            $rrd_def = RrdDefinition::make()->addDataset('LOAD', 'GAUGE', -1, 100);

            Log::info("CPU: $cpu_usage%");
            $fields = [
                'LOAD' => $cpu_usage,
            ];

            $tags = ['rrd_def' => $rrd_def];
            $datastore->put($this->getDeviceArray(), 'fortigate_cpu', $tags, $fields);
            $this->enableGraph('fortigate_cpu');
        }

        if ($this->hasApiConfig() && $this->isCapabilityEnabled('vpn-ssl-stats')) {
            $this->enableGraph('fortigate_vpn_ssl_stats');
        }
    }

    public function discoverWirelessClients()
    {
        $oid = '.1.3.6.1.4.1.12356.101.14.2.7.0';

        return [
            new WirelessSensor('clients', $this->getDeviceId(), $oid, 'fortigate', 1, 'Clients: Total'),
        ];
    }

    public function discoverWirelessApCount()
    {
        $oid = '.1.3.6.1.4.1.12356.101.14.2.5.0';

        return [
            new WirelessSensor('ap-count', $this->getDeviceId(), $oid, 'fortigate', 1, 'Connected APs'),
        ];
    }

    /**
     * Discover VPN SSL stats (via API)
     */
    public function discoverVpnSslStats()
    {
        if (!$this->hasApiConfig()) {
            return [];
        }

        try {
            $client = DeviceApiClientFactory::make($this->getDevice());
            if (!$client || !$this->isCapabilityEnabled('vpn-ssl-stats')) {
                return [];
            }

            // Fetch VPN SSL stats
            $statsData = $client->get('/api/v2/monitor/vpn/ssl');
            $stats = $this->normalizeData('Fortinet\VpnSslStats', $statsData);

            return $stats['sensors'] ?? [];
        } catch (\Exception $e) {
            Log::warning('FortiGate VPN SSL stats discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
