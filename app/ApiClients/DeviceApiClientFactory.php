<?php
namespace App\ApiClients;

use App\Models\Device;
use App\Models\DeviceApiConfig;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use Illuminate\Support\Facades\Log;

class DeviceApiClientFactory
{
    /**
     * Mapping of template keys to client classes
     */
    protected static array $templateToClient = [
        'purestorage_flasharray' => \App\ApiClients\PureStorage\FlashArrayClient::class,
        'proxmox_ve' => \App\ApiClients\Proxmox\ProxmoxApiClient::class,
    ];

    /**
     * Register vendor client classes here for auto-detection.
     * Each must implement DeviceApiClientInterface and define a VENDOR constant.
     */
    protected static array $clientClasses = [
        \App\ApiClients\PureStorage\FlashArrayClient::class,
        \App\ApiClients\Proxmox\ProxmoxApiClient::class,
    ];

    protected static array $cache = []; // device_id => class-string

    public static function make(Device $device): ?DeviceApiClientInterface
    {
        $id = (int) ($device->device_id ?? 0);
        if ($id && isset(self::$cache[$id])) {
            $class = self::$cache[$id];
            return new $class($device);
        }

        // Get template key from DeviceApiConfig
        $apiConfig = $device->apiConfig ?? DeviceApiConfig::with('template')->where('device_id', $device->device_id)->first();
        $templateKey = $apiConfig?->template?->key;

        if ($templateKey && isset(self::$templateToClient[$templateKey])) {
            $class = self::$templateToClient[$templateKey];
            self::$cache[$id] = $class;
            return new $class($device);
        }

        // Probe path: ask each client if it supports this device
        foreach (self::$clientClasses as $class) {
            try {
                $client = new $class($device);
                if ($client->supports($device)) {
                    self::$cache[$id] = $class;
                    return $client;
                }
            } catch (\Throwable $e) {
                Log::debug("API client probe failed for $class on device $id: " . $e->getMessage());
            }
        }

        return null;
    }
}