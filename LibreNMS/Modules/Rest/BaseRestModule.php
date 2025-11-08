<?php

namespace LibreNMS\Modules\Rest;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceApiClientFactory;
use App\Models\Device;
use App\Services\DeviceApiPersistor;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use Illuminate\Support\Facades\Log;

/**
 * BaseRestModule
 *
 * Base class for REST API modules. Provides common functionality for vendor-agnostic
 * REST API discovery and polling. Vendor-specific logic is handled through the
 * DeviceApiClientInterface implementation.
 */
abstract class BaseRestModule implements Module
{
    /**
     * The capability name this module requires (e.g., 'sensors', 'ports', 'inventory')
     */
    abstract protected function getRequiredCapability(): string;

    /**
     * Fetch data from the vendor client
     */
    abstract protected function fetchData(DeviceApiClientInterface $client, Device $device): array;

    /**
     * Persist the fetched data
     */
    abstract protected function persistData(Device $device, array $data): void;

    /**
     * Get the vendor API client for this device
     */
    protected function getClient(Device $device): ?DeviceApiClientInterface
    {
        try {
            return DeviceApiClientFactory::make($device);
        } catch (\Throwable $e) {
            Log::debug(static::class . " failed to create client for device {$device->device_id}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Check if this module should run for this device
     */
    protected function shouldRun(Device $device): bool
    {
        // Check if device has API config
        if (!$device->apiConfig) {
            return false;
        }

        // Get the client and check capabilities
        $client = $this->getClient($device);
        if (!$client) {
            return false;
        }

        $capabilities = $client->capabilities();
        $required = $this->getRequiredCapability();

        return in_array($required, $capabilities, true);
    }

    /**
     * @inheritDoc
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        if (!$status->isEnabledAndDeviceUp($os->getDevice())) {
            return false;
        }

        return $this->shouldRun($os->getDevice());
    }

    /**
     * @inheritDoc
     */
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        if (!$status->isEnabledAndDeviceUp($os->getDevice())) {
            return false;
        }

        return $this->shouldRun($os->getDevice());
    }

    /**
     * @inheritDoc
     */
    public function discover(OS $os): void
    {
        $device = $os->getDevice();
        $client = $this->getClient($device);

        if (!$client) {
            Log::debug(static::class . " skipped for device {$device->device_id}: no client available");
            return;
        }

        try {
            $data = $this->fetchData($client, $device);

            if (empty($data)) {
                Log::debug(static::class . " no data returned for device {$device->device_id}");
                return;
            }

            $this->persistData($device, $data);

            Log::info(static::class . " discovered " . count($data) . " items for device {$device->device_id}");
        } catch (\Throwable $e) {
            Log::error(static::class . " discovery failed for device {$device->device_id}: {$e->getMessage()}");
        }
    }

    /**
     * @inheritDoc
     * Default implementation does nothing - override in polling modules
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        // Most REST modules only need discovery
        // Override this in polling-specific modules like RestPortsStatistics
    }

    /**
     * @inheritDoc
     * Default implementation - override in specific modules
     */
    abstract public function dataExists(Device $device): bool;

    /**
     * @inheritDoc
     * Default implementation - override in specific modules
     */
    abstract public function cleanup(Device $device): int;

    /**
     * @inheritDoc
     * Default implementation - override in specific modules if needed
     */
    public function dump(Device $device, string $type): ?array
    {
        return null;
    }
}
