<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Discovery\RestApiDiscovery;
use App\Models\Device;
use Log;

class RestApiDiscoverCommand extends Command
{
    protected $signature = 'restapi:discover {device_id? : Optional device ID to discover a single device}';
    protected $description = 'Discover REST API endpoints, metrics, and capabilities for devices.';

    public function handle()
    {
        $deviceId = $this->argument('device_id');

        if ($deviceId) {
            $devices = Device::where('id', $deviceId)
                ->where('os', '!=', '')
                ->get();
        } else {
            $devices = Device::whereNotNull('os')->get();
        }

        foreach ($devices as $device) {
            if (!$device->restApiConnections()->exists()) {
                $this->info("Skipping {$device->hostname} — no REST API connections.");
                continue;
            }

            try {
                $this->info("Discovering REST APIs for device {$device->hostname}...");
                $discovery = new RestApiDiscovery($device);
                $discovery->discover();
                $this->info("Completed REST API discovery for {$device->hostname}");
            } catch (\Throwable $e) {
                $this->error("Error discovering {$device->hostname}: " . $e->getMessage());
                Log::error("REST API discovery error [{$device->hostname}]: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        }

        return Command::SUCCESS;
    }
}
