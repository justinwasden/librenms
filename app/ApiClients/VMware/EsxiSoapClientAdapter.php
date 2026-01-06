<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\TestableDevice;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\DeviceApiSettings;

/**
 * Adapter for EsxiSoapClient to implement DeviceApiClientInterface
 *
 * This adapter wraps the SOAP-based EsxiSoapClient to make it compatible
 * with the DeviceApiClientInterface used by REST-based modules.
 */
class EsxiSoapClientAdapter implements DeviceApiClientInterface
{
    public const VENDOR = 'vmware';

    protected EsxiSoapClient $soapClient;
    protected Device|TestableDevice $device;

    public function __construct(Device|TestableDevice $device)
    {
        $this->device = $device;

        // Get config from device attributes
        $baseUrl = $device->getAttrib('api_base_url');

        if (!$baseUrl) {
            throw new \RuntimeException("No saved API configuration found for device {$device->device_id}");
        }

        // Extract config values from attributes (decrypt credentials if needed)
        $config = [
            'hostname' => parse_url($baseUrl, PHP_URL_HOST) ?: $device->hostname,
            'username' => DeviceApiSettings::getCredential($device, 'api_credential_username'),
            'password' => DeviceApiSettings::getCredential($device, 'api_credential_password'),
            'verify_ssl' => (bool) $device->getAttrib('api_verify_ssl', false),
        ];

        $this->soapClient = new EsxiSoapClient($device, $config);
    }

    public function supports(Device|TestableDevice $device): bool
    {
        return $device->os === 'vmware-esxi' && $device->getAttrib('api_base_url') !== null;
    }

    public function capabilities(): array
    {
        return ['vminfo'];
    }

    public function fetchSensors(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchPorts(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchMempools(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchProcessors(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchInventory(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchStorage(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchTransceivers(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchIpv4Addresses(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchPortsStatistics(Device|TestableDevice $device): array
    {
        return [];
    }

    public function fetchVms(Device|TestableDevice $device): array
    {
        return $this->soapClient->fetchVms($device);
    }

    public function get(string $path, array $query = []): array
    {
        throw new \RuntimeException('ESXi SOAP client does not support generic GET requests');
    }

    public function post(string $path, array $body = []): array
    {
        throw new \RuntimeException('ESXi SOAP client does not support generic POST requests');
    }

    public function isReachable(): bool
    {
        return $this->soapClient->testConnection();
    }

    public function getApiInfo(): array
    {
        return [
            'vendor' => self::VENDOR,
            'base_url' => $this->device->getAttrib('api_base_url'),
            'api_type' => 'soap',
            'reachable' => $this->isReachable(),
        ];
    }
}
