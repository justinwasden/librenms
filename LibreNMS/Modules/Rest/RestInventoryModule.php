<?php

namespace LibreNMS\Modules\Rest;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Services\DeviceApiPersistor;

/**
 * RestInventoryModule
 *
 * Vendor-agnostic module for discovering inventory (entPhysical) via REST API.
 * Works with any vendor client that provides the 'inventory' capability.
 */
class RestInventoryModule extends BaseRestModule
{
    /**
     * @inheritDoc
     */
    protected function getRequiredCapability(): string
    {
        return 'inventory';
    }

    /**
     * @inheritDoc
     */
    protected function fetchData(DeviceApiClientInterface $client, Device $device): array
    {
        return $client->fetchInventory($device);
    }

    /**
     * @inheritDoc
     */
    protected function persistData(Device $device, array $data): void
    {
        DeviceApiPersistor::saveInventory($device, $data);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->entityPhysical()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->entityPhysical()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'entPhysical' => $device->entityPhysical()
                ->orderBy('entPhysicalIndex')
                ->get()
                ->map->makeHidden(['device_id', 'entPhysical_id'])
                ->toArray(),
        ];
    }
}
