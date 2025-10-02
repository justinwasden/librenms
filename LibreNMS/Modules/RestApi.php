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

        // Only poll if device has REST API connections
        return $device->restApiConnections()->exists() && $device->status;
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