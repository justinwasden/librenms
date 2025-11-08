<?php

namespace LibreNMS\Modules;

use App\Models\Device;
use App\Models\StorageArray as StorageArrayModel;
use App\Models\Storage as StorageModel;
use App\Models\Sensor;
use App\Models\Component;
use App\Models\Service;
use App\Models\StorageController;
use App\Models\StorageVolume;
use App\Models\StorageHost;
use App\Observers\ModuleModelObserver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\DB\SyncsModels;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;
use App\ApiClients\DeviceApiClientFactory;
use LibreNMS\Util\DeviceApiSettings;

class StorageArrays implements Module
{
    use SyncsModels;

    public function dependencies(): array
    {
        // We rely on ports, storage, sensors, components, services being available
        return ['ports', 'storage', 'sensors', 'inventory', 'processors', 'mempools', 'services'];
    }

    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return $status->isEnabledAndDeviceUp($os->getDevice());
    }

    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        return $status->isEnabledAndDeviceUp($os->getDevice());
    }

    public function discover(OS $os): void
    {
        $device = $os->getDevice();
        $array = $device->storageArray()->first() ?: new StorageArrayModel(['device_id' => $device->device_id]);

        // 1) Ingest vendor API volumes/pools into storage table (priority vendors)
        $this->discoverVolumesPoolsToStorage($device);

        // 2) Build roll-up from storage table entries for this device
        $storages = $device->storage()->get();
        $total = (int) $storages->sum('storage_size');
        $used = (int) $storages->sum('storage_used');
        $free = (int) $storages->sum('storage_free');
        $array->fillCapacity($used, $total, $free);

        // 3) Identity
        $array->vendor = $device->hardware ? strtok($device->hardware, ' ') : $device->os;
        $array->model = $device->hardware ?: null;
        $array->serial = $device->serial ?: null;
        $array->array_name = $device->sysName ?: $device->hostname;
        $array->software_version = $device->version ?: null;

        // 4) Counts - Use dedicated storage detail tables if available, fallback to old methods
        $array->controllers_count = StorageController::where('device_id', $device->device_id)->count();
        if ($array->controllers_count === 0) {
            // Fallback: Check components table for legacy data
            $array->controllers_count = Component::where('device_id', $device->device_id)
                ->where('type', 'like', '%controller%')->count();
        }

        $array->volumes_count = StorageVolume::where('device_id', $device->device_id)->count();
        if ($array->volumes_count === 0) {
            // Fallback: Use storage table count for arrays without detailed volume metrics
            $array->volumes_count = $storages->count();
        }

        $array->hosts_count = StorageHost::where('device_id', $device->device_id)->count();
        if ($array->hosts_count === 0) {
            // Fallback: Count unique MAC addresses on ports for legacy data
            $array->hosts_count = $device->ports()->whereNotNull('ifPhysAddress')->distinct('ifPhysAddress')->count('ifPhysAddress');
        }

        $array->replication_links_count = Service::where('device_id', $device->device_id)
            ->where('service_type', 'like', '%replication%')->count();

        // 5) Data Reduction Ratio from sensors (if present)
        $drr = Sensor::where('device_id', $device->device_id)
            ->where('sensor_class', 'storage_efficiency')
            ->value('sensor_current');
        $array->data_reduction_ratio = $drr !== null ? (float) $drr : null;

        // 6) Alerts open count (alert_log holds event history; the active alerts table tracks current)
        $array->alerts_open_count = \DB::table('alerts')
            ->where('device_id', $device->device_id)->where('state', '=', '1')->count();

        ModuleModelObserver::observe(StorageArrayModel::class, 'Storage Arrays');
        $this->syncModels($device, 'storageArray', collect([$array]));
        ModuleModelObserver::done();
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();
        /** @var StorageArrayModel|null $array */
        $array = $device->storageArray()->first();
        if (! $array) {
            $this->discover($os);
            $array = $device->storageArray()->first();
            if (! $array) {
                return;
            }
        }

        // Refresh volumes/pools storage entries via API on each poll to prevent drift
        $this->discoverVolumesPoolsToStorage($device);

        // Recompute roll-up on poll
        $storages = $device->storage()->get();
        $array->fillCapacity(
            (int) $storages->sum('storage_used'),
            (int) $storages->sum('storage_size'),
            (int) $storages->sum('storage_free'),
        );

        // Update counts from dedicated tables
        $array->controllers_count = StorageController::where('device_id', $device->device_id)->count();
        if ($array->controllers_count === 0) {
            $array->controllers_count = Component::where('device_id', $device->device_id)
                ->where('type', 'like', '%controller%')->count();
        }

        $array->volumes_count = StorageVolume::where('device_id', $device->device_id)->count();
        if ($array->volumes_count === 0) {
            $array->volumes_count = $storages->count();
        }

        $array->hosts_count = StorageHost::where('device_id', $device->device_id)->count();
        if ($array->hosts_count === 0) {
            $array->hosts_count = $device->ports()->whereNotNull('ifPhysAddress')->distinct('ifPhysAddress')->count('ifPhysAddress');
        }

        // Update DRR if available
        $drr = Sensor::where('device_id', $device->device_id)
            ->where('sensor_class', 'storage_efficiency')
            ->value('sensor_current');
        $array->data_reduction_ratio = $drr !== null ? (float) $drr : null;

        $array->last_polled_at = now();
        $array->save();
    }

    public function cleanup(Device $device): int
    {
        return $device->storageArray()->delete();
    }

    public function dataExists(Device $device): bool
    {
        return $device->storageArray()->exists();
    }

    public function dump(Device $device, string $type): ?array
    {
        // skip poller dump same as discovery
        if ($type === 'poller') {
            return null;
        }

        return [
            'storage_array' => $device->storageArray()->get()->map->makeHidden(['device_id', 'id']),
        ];
    }

    private function discoverVolumesPoolsToStorage(Device $device): void
    {
        // NOTE: We do NOT call the API here. The device-api poller module
        // already handles API calls via configured endpoints, which populate
        // the storage table through normalizers and DeviceApiPersistor.
        //
        // This module's job is to:
        // 1. Read from the storage table (already populated by device-api)
        // 2. Create a roll-up summary in storage_arrays
        //
        // The flow is:
        //   device-api poller → API endpoint → normalizer → DeviceApiPersistor → storage table
        //   storage-arrays module → reads storage table → creates summary in storage_arrays

        // Nothing to do here - storage table is populated by device-api module
        return;
    }
}
