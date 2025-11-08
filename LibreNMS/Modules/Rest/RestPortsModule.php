<?php

namespace LibreNMS\Modules\Rest;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Services\DeviceApiPersistor;

/**
 * RestPortsModule
 *
 * Vendor-agnostic module for discovering network ports via REST API.
 * Works with any vendor client that provides the 'ports' capability.
 */
class RestPortsModule extends BaseRestModule
{
    /**
     * @inheritDoc
     */
    protected function getRequiredCapability(): string
    {
        return 'ports';
    }

    /**
     * @inheritDoc
     */
    protected function fetchData(DeviceApiClientInterface $client, Device $device): array
    {
        return $client->fetchPorts($device);
    }

    /**
     * @inheritDoc
     */
    protected function persistData(Device $device, array $data): void
    {
        DeviceApiPersistor::savePorts($device, $data);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->ports()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->ports()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'ports' => $device->ports()
                ->orderBy('ifIndex')
                ->get()
                ->map->makeHidden(['device_id', 'port_id'])
                ->toArray(),
        ];
    }
}
