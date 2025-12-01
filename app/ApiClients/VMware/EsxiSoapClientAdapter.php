<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Log;

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
    protected Device $device;
    protected ?DeviceApiConfig $apiConfig = null;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load API config
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        if (!$this->apiConfig) {
            throw new \RuntimeException("No saved API configuration found for device {$device->device_id}");
        }

        // Extract config values
        $config = [
            'hostname' => $this->apiConfig->base_url ? parse_url($this->apiConfig->base_url, PHP_URL_HOST) : $device->hostname,
            'username' => $this->apiConfig->getValue('username'),
            'password' => $this->apiConfig->getValue('password'),
            'verify_ssl' => (bool) ($this->apiConfig->verify_ssl ?? false),
        ];

        $this->soapClient = new EsxiSoapClient($device, $config);
    }

    public function supports(Device $device): bool
    {
        return $device->os === 'vmware-esxi' && $this->apiConfig !== null;
    }

    public function capabilities(): array
    {
        return ['vminfo'];
    }

    public function fetchSensors(Device $device): array
    {
        return [];
    }

    public function fetchPorts(Device $device): array
    {
        return [];
    }

    public function fetchMempools(Device $device): array
    {
        return [];
    }

    public function fetchProcessors(Device $device): array
    {
        return [];
    }

    public function fetchInventory(Device $device): array
    {
        return [];
    }

    public function fetchStorage(Device $device): array
    {
        return [];
    }

    public function fetchTransceivers(Device $device): array
    {
        return [];
    }

    public function fetchIpv4Addresses(Device $device): array
    {
        return [];
    }

    public function fetchPortsStatistics(Device $device): array
    {
        return [];
    }

    public function fetchVms(Device $device): array
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
            'base_url' => $this->apiConfig->base_url ?? null,
            'api_type' => 'soap',
            'reachable' => $this->isReachable(),
        ];
    }
}
