<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Services\Clusters\VcenterClusterPoller;
use App\Services\Clusters\HypervClusterPoller;
use App\Services\Clusters\StorageArrayClusterPoller;
use App\Services\Clusters\ProxmoxClusterPoller;

class PollClusters extends Command
{
    protected $signature = 'clusters:poll {device_id?}';
    protected $description = 'Poll cluster data for devices and upsert into unified schema';

    public function handle(): int
    {
        $deviceId = $this->argument('device_id');

        $devices = Device::query()
            ->when($deviceId, fn($q) => $q->where('device_id', (int) $deviceId))
            ->whereIn('os', ['vmware-vcsa', 'hyperv', 'proxmox', 'purestorage']) // adjust OS keys
            ->get();

        foreach ($devices as $device) {
            $this->info('Polling clusters for device: ' . $device->displayName() . ' (OS: ' . $device->os . ')');

            $counts = ['clusters' => 0, 'nodes' => 0, 'metrics' => 0];

            switch ($device->os) {
                case 'vmware-vcsa':
                    $counts = app(VcenterClusterPoller::class)->poll($device->device_id);
                    break;
                case 'hyperv':
                    $counts = app(HypervClusterPoller::class)->poll($device->device_id);
                    break;
                case 'purestorage':
                    $counts = app(StorageArrayClusterPoller::class)->poll($device->device_id);
                    break;
                case 'proxmox':
                    $counts = app(ProxmoxClusterPoller::class)->poll($device->device_id);
                    break;
                default:
                    $this->warn('No poller implemented for OS: ' . $device->os);
                    continue 2;
            }

            $this->info(sprintf('Upserted clusters=%d nodes=%d metrics=%d', $counts['clusters'], $counts['nodes'], $counts['metrics']));
        }

        return self::SUCCESS;
    }
}
