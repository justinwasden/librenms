<?php
/**
 * app/Console/Commands/DiscoverRestApi.php
 */
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Discovery\RestApiDiscovery;
use Illuminate\Support\Facades\Log;

class DiscoverRestApi extends Command
{
    protected $signature = 'device:discover-restapi {device_id?}';
    protected $description = 'Perform REST API discovery for one or all devices';

    public function handle()
    {
        $query = Device::query()->whereHas('restApiConnections', fn($q) => $q->where('enabled', 1));
        if ($this->argument('device_id')) {
            $query->where('device_id', $this->argument('device_id'));
        }

        $devices = $query->get();
        if ($devices->isEmpty()) {
            $this->warn('No devices with REST API connections found.');
            return 0;
        }

        foreach ($devices as $device) {
            $this->info("Starting REST API discovery for {$device->hostname}");
            try {
                (new RestApiDiscovery($device))->discover();
            } catch (\Throwable $e) {
                Log::error("Discovery failed for {$device->hostname}: {$e->getMessage()}");
                $this->error("Error: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
