<?php

namespace App\ApiClients\VMware;

use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Log;

/**
 * Factory for creating EsxiSoapClient instances from DeviceApiConfig
 */
class EsxiSoapClientFactory
{
    /**
     * Create an EsxiSoapClient from a device's API configuration
     *
     * @param Device $device
     * @return EsxiSoapClient|null
     */
    public static function makeFromDevice(Device $device): ?EsxiSoapClient
    {
        // Get SOAP API config for this device (schema_id 20 = esxi_soap)
        $apiConfig = DeviceApiConfig::where('device_id', $device->device_id)
            ->where('schema_id', 20)
            ->first();

        if (!$apiConfig) {
            Log::debug("EsxiSoapClientFactory: No SOAP API config found for device {$device->device_id}");
            return null;
        }

        return static::makeFromConfig($device, $apiConfig);
    }

    /**
     * Create an EsxiSoapClient from a DeviceApiConfig model
     *
     * @param Device $device
     * @param DeviceApiConfig $apiConfig
     * @return EsxiSoapClient
     */
    public static function makeFromConfig(Device $device, DeviceApiConfig $apiConfig): EsxiSoapClient
    {
        // Use getValue() method to properly decrypt encrypted fields
        // The DeviceApiConfig model handles encryption/decryption based on auth schema field settings
        $hostname = $apiConfig->getValue('hostname', $device->hostname);
        $username = $apiConfig->getValue('username', 'root');
        $password = $apiConfig->getValue('password', '');

        // Use the global verify_ssl checkbox from device_api_configs table
        $verifySSL = (bool) ($apiConfig->verify_ssl ?? false);

        Log::debug("EsxiSoapClientFactory: Creating client for device {$device->device_id}", [
            'hostname' => $hostname,
            'username' => $username,
            'verify_ssl' => $verifySSL,
        ]);

        return new EsxiSoapClient($device, [
            'hostname' => $hostname,
            'username' => $username,
            'password' => $password,
            'verify_ssl' => $verifySSL,
        ]);
    }

    /**
     * Test if a device has SOAP API configured
     *
     * @param Device $device
     * @return bool
     */
    public static function hasConfig(Device $device): bool
    {
        return DeviceApiConfig::where('device_id', $device->device_id)
            ->where('schema_id', 20)
            ->exists();
    }
}
