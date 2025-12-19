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
 * Works with any vendor client that provides the 'ports_statistics' capability.
 */
class RestPortsStatisticsModule extends BaseRestModule
{
    protected function getRequiredCapability(): string
    {
        // Standardized capability name
        return 'ports_statistics';
    }

    protected function fetchData(DeviceApiClientInterface $client, Device $device): array
    {
        return $client->fetchPortsStatistics($device);
    }

    protected function persistData(Device $device, array $data): void
    {
        DeviceApiPersistor::savePortsStatistics($device, $data);
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $this->discover($os);
    }

    public function dataExists(Device $device): bool
    {
        return $device->ports()->exists();
    }

    public function cleanup(Device $device): int
    {
        return 0;
    }

    public function dump(Device $device, string $type): ?array
    {
        return null;
    }
}