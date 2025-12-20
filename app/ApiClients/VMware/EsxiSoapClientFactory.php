<?php

namespace App\ApiClients\VMware;

use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * Factory for creating EsxiSoapClient instances from device attributes
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
        // Read from device attributes
        $baseUrl = $device->getAttrib('api_base_url');

        if (!$baseUrl) {
            Log::debug("EsxiSoapClientFactory: No API config found for device {$device->device_id}");
            return null;
        }

        $hostname = $device->getAttrib('api_credential_hostname', $device->hostname);
        $username = $device->getAttrib('api_credential_username', 'root');
        $password = $device->getAttrib('api_credential_password', '');
        $verifySSL = (bool) $device->getAttrib('api_verify_ssl', false);

        Log::debug("EsxiSoapClientFactory: Creating client from attributes for device {$device->device_id}", [
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
        return $device->getAttrib('api_base_url') !== null;
    }
}
