<?php

namespace LibreNMS\OS;

use App\ApiClients\DeviceApiClientFactory;
use App\Models\Storage;
use Illuminate\Support\Collection;
use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Polling\OSPolling;
use LibreNMS\OS\Traits\ApiPolling;
use LibreNMS\RRD\RrdDefinition;
use SnmpQuery;

class Purestorage extends \LibreNMS\OS implements OSPolling, ProcessorDiscovery
{
    use ApiPolling;

    /**
     * Discover processors (via API if available, otherwise SNMP)
     *
     * Returns an array of LibreNMS\Device\Processor objects as required by the
     * ProcessorDiscovery interface.
     *
     * @return array<Processor>
     */
    public function discoverProcessors()
    {
        // Try API discovery first
        if ($this->hasApiConfig()) {
            try {
                $client = DeviceApiClientFactory::make($this->getDevice());
                if ($client && in_array('processors', $client->capabilities())) {
                    $apiData = $client->get('/controllers');
                    $normalized = $this->normalizeData('Pure\Controllers', $apiData);

                    // Extract processor data and convert to Processor objects
                    if (!empty($normalized['processors'])) {
                        $processors = [];
                        foreach ($normalized['processors'] as $proc) {
                            $processors[] = Processor::discover(
                                $proc['processor_type'] ?? 'purestorage',
                                $this->getDeviceId(),
                                '',  // No OID for API-sourced data
                                $proc['processor_index'] ?? 0,
                                $proc['processor_descr'] ?? 'Controller',
                                1,   // precision
                                $proc['processor_usage'] ?? null,
                                null // warn_percent
                            );
                        }

                        if (!empty($processors)) {
                            \Log::info('Pure Storage: Discovered ' . count($processors) . ' processors via API');
                            return $processors;
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::debug('Pure API processor discovery failed, falling back to SNMP', [
                    'device_id' => $this->getDevice()->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to SNMP discovery (let parent/YAML handle it)
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
                    // Fetch array sensors (temperature, power, etc.)
                    $sensorData = $client->get('/arrays');
                    $apiSensors = $this->normalizeData('Pure\ArraySensors', $sensorData);

                    if (!empty($apiSensors)) {
                        $sensors = array_merge($sensors, $apiSensors);
                    }

                    // Fetch hardware sensors
                    $hardwareData = $client->get('/hardware');
                    $hardwareSensors = $this->normalizeData('Pure\Hardware', $hardwareData);

                    if (!empty($hardwareSensors)) {
                        $sensors = array_merge($sensors, $hardwareSensors);
                    }
                }
            } catch (\Exception $e) {
                \Log::debug('Pure API sensor discovery failed, falling back to SNMP', [
                    'device_id' => $this->getDevice()->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sensors;
    }

    /**
     * Discover storage (via API)
     *
     * Returns a Collection of Storage model instances for native module compatibility
     */
    public function discoverStorage(): Collection
    {
        // Try API-based discovery first
        if ($this->hasApiConfig()) {
            try {
                $client = DeviceApiClientFactory::make($this->getDevice());
                if ($client && in_array('storage', $client->capabilities())) {
                    $volumeData = $client->get('/volumes');
                    $normalized = $this->normalizeData('Pure\VolumesToStorage', $volumeData);

                    if (!empty($normalized)) {
                        // Convert normalized arrays to Storage model instances
                        return collect($normalized)->map(function ($item) {
                            return new Storage($item);
                        });
                    }
                }
            } catch (\Exception $e) {
                \Log::debug('Pure API storage discovery failed, falling back to SNMP', [
                    'device_id' => $this->getDevice()->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to parent SNMP-based discovery
        return parent::discoverStorage();
    }

    /**
     * Discover ports (via API)
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

            // Fetch network interfaces
            $interfaceData = $client->get('/network-interfaces');
            $ports = $this->normalizeData('Pure\NetworkInterfaces', $interfaceData);

            return $ports ?? [];
        } catch (\Exception $e) {
            \Log::warning('Pure ports discovery failed', [
                'device_id' => $this->getDevice()->device_id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Poll Pure Storage array metrics via SNMP
     */
    public function pollOS(DataStorageInterface $datastore): void
    {
        // Pure Storage SNMP metrics OIDs (from PURESTORAGE-MIB)
        // Using numeric OIDs directly
        $metrics = [
            'pureArrayReadBandwidth' => '.1.3.6.1.4.1.40482.4.1.0',    // bytes/sec
            'pureArrayWriteBandwidth' => '.1.3.6.1.4.1.40482.4.2.0',    // bytes/sec
            'pureArrayReadIOPS' => '.1.3.6.1.4.1.40482.4.3.0',    // ops/sec
            'pureArrayWriteIOPS' => '.1.3.6.1.4.1.40482.4.4.0',    // ops/sec
            'pureArrayReadLatency' => '.1.3.6.1.4.1.40482.4.5.0',    // microseconds
            'pureArrayWriteLatency' => '.1.3.6.1.4.1.40482.4.6.0',    // microseconds
        ];

        // Query all OIDs at once
        $data = [];
        foreach ($metrics as $name => $oid) {
            $value = SnmpQuery::get($oid)->value();
            // Cast to integer, filtering out non-numeric values
            if (is_numeric($value)) {
                $data[$name] = (int) $value;
                echo "[Purestorage] $name = $value\n";
            } else {
                echo "[Purestorage] WARNING: $name has non-numeric value: $value\n";
            }
        }

        if (empty($data)) {
            echo "[Purestorage] No valid metrics returned from SNMP\n";

            return;
        }

        echo '[Purestorage] Polling ' . count($data) . " metrics\n";

        // Store metrics in RRD files
        $this->storeBandwidth($datastore, $data);
        $this->storeIOPS($datastore, $data);
        $this->storeLatency($datastore, $data);

        // Enable graphs for display
        $this->enableGraph('purestorage_bandwidth');
        $this->enableGraph('purestorage_iops');
        $this->enableGraph('purestorage_latency');
    }

    /**
     * Store bandwidth metrics in RRD
     * Bandwidth is in bytes/second and will be converted to bits/second by the YAML RPN
     */
    private function storeBandwidth(DataStorageInterface $datastore, $data): void
    {
        $rrd_name = 'purestorage_bandwidth';

        $rrd_def = RrdDefinition::make()
            ->addDataset('read', 'GAUGE', 0, 125000000000)      // max 125 Gbps
            ->addDataset('write', 'GAUGE', 0, 125000000000);

        $read = isset($data['pureArrayReadBandwidth']) ? (int) $data['pureArrayReadBandwidth'] : 0;
        $write = isset($data['pureArrayWriteBandwidth']) ? (int) $data['pureArrayWriteBandwidth'] : 0;

        $fields = [
            'read' => $read,
            'write' => $write,
        ];

        echo "[Purestorage] Bandwidth - read: $read, write: $write\n";

        $tags = ['rrd_def' => $rrd_def];
        $datastore->put($this->getDeviceArray(), $rrd_name, $tags, $fields);
        echo "[Purestorage] Stored bandwidth metrics\n";
    }

    /**
     * Store IOPS metrics in RRD
     * Operations per second (no conversion needed)
     */
    private function storeIOPS(DataStorageInterface $datastore, $data): void
    {
        $rrd_name = 'purestorage_iops';

        $rrd_def = RrdDefinition::make()
            ->addDataset('read', 'DERIVE', 0, 1000000000)        // max 1B ops/sec
            ->addDataset('write', 'DERIVE', 0, 1000000000);

        $read = isset($data['pureArrayReadIOPS']) ? (int) $data['pureArrayReadIOPS'] : 0;
        $write = isset($data['pureArrayWriteIOPS']) ? (int) $data['pureArrayWriteIOPS'] : 0;

        $fields = [
            'read' => $read,
            'write' => $write,
        ];

        echo "[Purestorage] IOPS - read: $read, write: $write\n";

        $tags = ['rrd_def' => $rrd_def];
        $datastore->put($this->getDeviceArray(), $rrd_name, $tags, $fields);
        echo "[Purestorage] Stored IOPS metrics\n";
    }

    /**
     * Store latency metrics in RRD
     * Latency is in microseconds and will be converted to milliseconds by the YAML RPN
     */
    private function storeLatency(DataStorageInterface $datastore, $data): void
    {
        $rrd_name = 'purestorage_latency';

        $rrd_def = RrdDefinition::make()
            ->addDataset('read', 'GAUGE', 0, 1000000)            // max 1 second in µs
            ->addDataset('write', 'GAUGE', 0, 1000000);

        $read = isset($data['pureArrayReadLatency']) ? (int) $data['pureArrayReadLatency'] : 0;
        $write = isset($data['pureArrayWriteLatency']) ? (int) $data['pureArrayWriteLatency'] : 0;

        $fields = [
            'read' => $read,
            'write' => $write,
        ];

        echo "[Purestorage] Latency - read: $read, write: $write\n";

        $tags = ['rrd_def' => $rrd_def];
        $datastore->put($this->getDeviceArray(), $rrd_name, $tags, $fields);
        echo "[Purestorage] Stored latency metrics\n";
    }
}
