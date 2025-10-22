<?php
namespace LibreNMS\Modules;

use LibreNMS\Polling\ModuleStatus;
use LibreNMS\OS;
use App\Models\Device;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use Log;
use App\Services\RestApi\RestApiPollerService;

class RestApi implements Module
{
    protected RestApiPollerService $pollerService;

    public function __construct()
    {
        $this->pollerService = app(RestApiPollerService::class);
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
		{
		    $device = $os->getDevice();
		    if ($this->deviceHasApiConnections($device->id)) {
		        $this->pollerService->pollDeviceById($device->id);
		    }
		}

		public function discover(OS $os): void
		{
		    $device = $os->getDevice();
		    if ($this->deviceHasApiConnections($device->id)) {
		        $this->pollerService->discoverDeviceById($device->id);
		    }
		}

		public function shouldPoll(OS $os, ModuleStatus $status): bool
		{
		    $device = $os->getDevice();
		    return $this->deviceHasApiConnections($device->id);
		}

		public function shouldDiscover(OS $os, ModuleStatus $status): bool
		{
		    $device = $os->getDevice();
		    return $this->deviceHasApiConnections($device->id);
		}

    public function dataExists(Device $device): bool
		{
		    return $this->deviceHasApiConnections($device->id);
		}

    public function cleanup(Device $device): int
		{
		    $count = 0;

		    // Delete all REST API connections and cascade deletes to endpoints/metrics
		    $connections = $device->restApiConnections();
		    $count += $connections->count();
		    $connections->delete();

		    return $count;
		}
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
    public function dependencies(): array
    {
        return [];
    }

    protected function deviceHasApiConnections(int $deviceId): bool
    {
        return \DB::table('rest_api_connections')
            ->where('device_id', $deviceId)
            ->exists();
    }
}
