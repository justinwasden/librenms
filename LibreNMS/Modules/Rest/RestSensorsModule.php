<?php

namespace LibreNMS\Modules\Rest;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Services\DeviceApiPersistor;

/**
 * RestSensorsModule
 *
 * Vendor-agnostic module for discovering sensors via REST API.
 * Works with any vendor client that provides the 'sensors' capability.
 */
class RestSensorsModule extends BaseRestModule
{
    /**
     * @inheritDoc
     */
    protected function getRequiredCapability(): string
    {
        return 'sensors';
    }

    /**
     * @inheritDoc
     */
    protected function fetchData(DeviceApiClientInterface $client, Device $device): array
    {
        return $client->fetchSensors($device);
    }

    /**
     * @inheritDoc
     */
    protected function persistData(Device $device, array $data): void
    {
        DeviceApiPersistor::saveSensors($device, $data);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->sensors()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->sensors()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'sensors' => $device->sensors()
                ->orderBy('sensor_class')
                ->orderBy('sensor_index')
                ->get()
                ->map->makeHidden(['device_id', 'sensor_id'])
                ->toArray(),
        ];
    }
}
