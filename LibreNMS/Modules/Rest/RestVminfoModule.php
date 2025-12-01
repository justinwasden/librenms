<?php

namespace LibreNMS\Modules\Rest;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Services\DeviceApiPersistor;

/**
 * RestVminfoModule
 *
 * Vendor-agnostic module for discovering virtual machines via REST API.
 * Works with any vendor client that provides the 'vminfo' capability.
 * Consolidates VM information from Proxmox, vCenter, ESXi SOAP, and other hypervisors
 * into the unified vminfo table for consistent presentation across all platforms.
 */
class RestVminfoModule extends BaseRestModule
{
    /**
     * @inheritDoc
     */
    protected function getRequiredCapability(): string
    {
        return 'vminfo';
    }

    /**
     * @inheritDoc
     */
    protected function fetchData(DeviceApiClientInterface $client, Device $device): array
    {
        return $client->fetchVms($device);
    }

    /**
     * @inheritDoc
     */
    protected function persistData(Device $device, array $data): void
    {
        DeviceApiPersistor::saveVminfo($device, $data);
    }

    /**
     * @inheritDoc
     */
    public function dataExists(Device $device): bool
    {
        return $device->vminfo()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->vminfo()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'vminfo' => $device->vminfo()
                ->orderBy('vmwVmVMID')
                ->get()
                ->map->makeHidden(['id', 'device_id'])
                ->toArray(),
        ];
    }
}
