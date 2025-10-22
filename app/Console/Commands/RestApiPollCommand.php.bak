<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Pollers\RestApiPoller;
use App\Models\Device;
use Log;

class RestApiPollCommand extends Command
{
    protected $signature = 'restapi:poll {device_id? : Optional device ID to poll a single device}';
    protected $description = 'Poll REST API-enabled devices for metrics.';

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
                $this->info("Polling REST APIs for device {$device->hostname}...");
                $poller = new RestApiPoller($device);
                $poller->poll();
                $this->info("Completed REST API polling for {$device->hostname}");
            } catch (\Throwable $e) {
                $this->error("Error polling {$device->hostname}: " . $e->getMessage());
                Log::error("REST API poll error [{$device->hostname}]: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        }

        return Command::SUCCESS;
    }
}
