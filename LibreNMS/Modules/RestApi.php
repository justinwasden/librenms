<?php
/**
 * RestApi.php
 *
 * REST API Discovery and Polling Module for LibreNMS v25+
 *
 * Fully implements Module interface and integrates with RestApiPollerService.
 */

namespace LibreNMS\Modules;

use App\Models\Device;
use App\Services\RestApi\RestApiPollerService;
use LibreNMS\Polling\ModuleStatus;
use LibreNMS\OS;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use Log;

class RestApi implements Module
{
    protected RestApiPollerService $pollerService;

    public function __construct()
    {
        // Inject your poller service via Laravel's container
        $this->pollerService = app(RestApiPollerService::class);
    }

    /**
     * Poll device metrics
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
{
    $device = $os->getDevice();
    Log::info("REST API poll running for device {$device->hostname}");
    if ($this->deviceHasApiConnections($device->id)) {
        $this->pollerService->pollDeviceById($device->id);
    }
}

    /**
     * Should we poll this device?
     */
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();
        return $this->deviceHasApiConnections($device->id);
    }

    /**
     * Should we run discovery for this device?
     */
    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();
        return $this->deviceHasApiConnections($device->id);
    }

    /**
     * Discover the device
     */
    public function discover(OS $os): void
    {
        $device = $os->getDevice();
        if ($this->deviceHasApiConnections($device->id)) {
            $this->pollerService->discoverDeviceById($device->id);
        }
    }

    /**
     * Check if data exists for this module
     */
    public function dataExists(Device $device): bool
    {
        return $this->deviceHasApiConnections($device->id);
    }

    /**
     * Cleanup module data
     */
    public function cleanup(Device $device): int
    {
        $count = 0;

        $connections = $device->restApiConnections();
        $count += $connections->count();
        $connections->delete();

        return $count;
    }

    /**
     * Dump current module data
     */
    public function dump(Device $device, string $type): ?array
    {
        return [
            'rest_api_connections' => $device->restApiConnections()
                ->with(['credential', 'endpoints'])
                ->get()
                ->map(function ($conn) {
                    return [
                        'name' => $conn->name,
                        'base_url' => $conn->base_url,
                        'enabled' => $conn->enabled,
                        'credential_type' => $conn->credential?->authenticationType?->name,
                        'endpoints_count' => $conn->endpoints->count(),
                    ];
                })
                ->toArray(),
        ];
    }

    /**
     * Return module dependencies
     */
    public function dependencies(): array
    {
        return [];
    }

    /**
     * Return module name
     */
    public function getName(): string
    {
        return 'REST API';
    }

    /**
     * Return module version
     */
    public function getVersion(): string
    {
        return '1.0';
    }

    /**
     * Return module author
     */
    public function getAuthor(): string
    {
        return 'JDub';
    }

    /**
     * Check if the device has API connections
     */
    protected function deviceHasApiConnections(?int $deviceId): bool
		{
		    if (!$deviceId) {
		        return false; // safely skip
		    }

		    return \DB::table('rest_api_connections')
		        ->where('device_id', $deviceId)
		        ->exists();
		}
}
