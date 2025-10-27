<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;

class PollDeviceApiAll extends Command
{
    protected $signature = 'device:poll-api-all {--group=} {--os=}';
    protected $description = 'Poll REST API endpoints for all devices with REST enabled';

    public function handle(): int
    {
        $query = Device::query()->with('attribs')
            ->whereHas('attribs', function ($q) {
                $q->where('attrib_key', 'rest_enabled')->where('attrib_value', '1');
            });

        if ($group = $this->option('group')) {
            $query->whereHas('groups', function ($q) use ($group) {
                $q->where('group_name', $group);
            });
        }
        if ($os = $this->option('os')) {
            $query->where('os', $os);
        }

        $count = 0;
        $failed = 0;

        $query->chunkById(100, function ($devices) use (&$count, &$failed) {
            foreach ($devices as $device) {
                $count++;
                $exitCode = \Artisan::call('device:poll-api', ['device_id' => $device->device_id]);
                if ($exitCode !== 0) {
                    $failed++;
                    $this->warn("API poll failed for device {$device->device_id}");
                }
            }
        });

        $this->info("Polled {$count} devices. Failed: {$failed}");
        return $failed === 0 ? 0 : 1;
    }
}