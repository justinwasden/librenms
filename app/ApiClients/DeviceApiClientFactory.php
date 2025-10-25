<?php
namespace App\ApiClients;

use App\Models\Device;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use Illuminate\Support\Facades\Log;

class DeviceApiClientFactory
{
    /**
     * Register vendor client classes here.
     * Each must implement DeviceApiClientInterface and define a VENDOR constant.
     */
    protected static array $clientClasses = [
        \App\ApiClients\PureStorage\FlashArrayClient::class,
        \App\ApiClients\Proxmox\ProxmoxClient::class,
    ];

    protected static array $cache = []; // device_id => class-string

    public static function make(Device $device): ?DeviceApiClientInterface
    {
        $id = (int) ($device->device_id ?? 0);
        if ($id && isset(self::$cache[$id])) {
            $class = self::$cache[$id];
            return new $class($device);
        }

        // Fast path: vendor attribute on device
        $attrVendor = $device->attribs['rest_vendor'] ?? null;
        if ($attrVendor) {
            foreach (self::$clientClasses as $class) {
                if (defined("$class::VENDOR") && $class::VENDOR === $attrVendor) {
                    self::$cache[$id] = $class;
                    return new $class($device);
                }
            }
        }

        // Probe path: ask each client if it supports this device
        foreach (self::$clientClasses as $class) {
            try {
                $client = new $class($device);
                if ($client->supports($device)) {
                    self::$cache[$id] = $class;
                    // Optionally persist rest_vendor to avoid future probes:
                    // $device->setAttrib('rest_vendor', $class::VENDOR); $device->save();
                    return $client;
                }
            } catch (\Throwable $e) {
                Log::debug("API client probe failed for $class on device $id: " . $e->getMessage());
            }
        }

        return null;
    }
}