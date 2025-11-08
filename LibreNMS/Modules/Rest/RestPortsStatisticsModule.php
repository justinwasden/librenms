<?php

namespace LibreNMS\Modules\Rest;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Services\DeviceApiPersistor;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\OS;

/**
 * RestPortsStatisticsModule
 *
 * Vendor-agnostic module for polling port statistics via REST API.
 * Works with any vendor client that provides the 'ports_stats' capability.
 * This is a polling module that runs frequently to collect traffic counters.
 */
class RestPortsStatisticsModule extends BaseRestModule
{
    /**
     * @inheritDoc
     */
    protected function getRequiredCapability(): string
    {
        return 'ports_stats';
    }

    /**
     * @inheritDoc
     */
    protected function fetchData(DeviceApiClientInterface $client, Device $device): array
    {
        return $client->fetchPortsStatistics($device);
    }

    /**
     * @inheritDoc
     */
    protected function persistData(Device $device, array $data): void
    {
        DeviceApiPersistor::savePortsStatistics($device, $data);
    }

    /**
     * @inheritDoc
     * Override poll to use the same discovery logic
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        // For ports statistics, polling is the same as discovery
        $this->discover($os);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        // Ports statistics are stored in RRD files, so check if ports exist
        return $device->ports()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        // Ports statistics are stored in RRD files
        // No DB cleanup needed specific to this module
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        // Statistics are time-series data in RRD, not suitable for dumping
        return null;
    }
}
