<?php

namespace LibreNMS\Modules\Rest;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Services\DeviceApiPersistor;

/**
 * RestIpv4Module
 *
 * Vendor-agnostic module for discovering IPv4 addresses via REST API.
 * Works with any vendor client that provides the 'ipv4' capability.
 */
class RestIpv4Module extends BaseRestModule
{
    /**
     * @inheritDoc
     */
    protected function getRequiredCapability(): string
    {
        return 'ipv4';
    }

    /**
     * @inheritDoc
     */
    protected function fetchData(DeviceApiClientInterface $client, Device $device): array
    {
        return $client->fetchIpv4Addresses($device);
    }

    /**
     * @inheritDoc
     */
    protected function persistData(Device $device, array $data): void
    {
        DeviceApiPersistor::saveIpv4Addresses($device, $data);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->ipv4()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->ipv4()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'ipv4_addresses' => $device->ipv4()
                ->orderBy('ipv4_address')
                ->get()
                ->map->makeHidden(['device_id', 'ipv4_address_id'])
                ->toArray(),
        ];
    }
}
