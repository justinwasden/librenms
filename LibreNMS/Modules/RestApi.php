<?php

namespace LibreNMS\Modules;

use App\Models\Device;
use App\Pollers\Api as ApiPoller;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;

class RestApi implements Module
{
    public function dependencies(): array
    {
        return [];
    }

    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return false; // REST API doesn't need discovery
    }

    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        $device = $os->getDevice();

        // Only poll if:
        // 1. Device has REST API connections
        // 2. At least one connection is enabled
        // 3. Device status is up
        // 4. Device is not disabled
        // 5. Device is not ignored
        if ($device->disabled || $device->ignore || !$device->status) {
            return false;
        }

        // Check if device has any enabled REST API connections
        return $device->restApiConnections()->where('enabled', 1)->exists();
    }

    public function discover(OS $os): void
    {
        // Not needed for REST API polling
    }

    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        $device = $os->getDevice();

        $poller = new ApiPoller($device);
        $poller->poll();
    }

    public function dataExists(Device $device): bool
    {
        return $device->restApiConnections()->exists();
    }

    public function cleanup(Device $device): int
    {
        // Clean up REST API data when module is disabled
        $count = 0;

        foreach ($device->restApiConnections as $connection) {
            foreach ($connection->endpoints as $endpoint) {
                $count += $endpoint->metrics()->delete();
            }
        }

        return $count;
    }

    public function dump(Device $device, string $type): ?array
    {
        if ($type !== 'poller') {
            return null;
        }

        $data = [];
        foreach ($device->restApiConnections as $connection) {
            foreach ($connection->endpoints as $endpoint) {
                $data[] = [
                    'connection' => $connection->name,
                    'endpoint' => $endpoint->name,
                    'last_polled' => $endpoint->last_polled?->toDateTimeString(),
                    'metrics_count' => $endpoint->metrics()->count(),
                ];
            }
        }

        return $data;
    }
}