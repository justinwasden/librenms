<?php
/**
 * app/Console/Commands/PollRestApi.php
 */
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Pollers\ApiPoller;
use Illuminate\Support\Facades\Log;

class PollRestApi extends Command
{
    protected $signature = 'device:poll-restapi {device_id?}';
    protected $description = 'Poll REST API metrics for one or all devices';

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
            $this->info("Polling REST API for {$device->hostname}");
            try {
                (new ApiPoller($device))->poll();
            } catch (\Throwable $e) {
                Log::error("Polling failed for {$device->hostname}: {$e->getMessage()}");
                $this->error("Error: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
