<?php
namespace App\ApiClients\PureStorage;

use App\Models\Device;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use LibreNMS\Modules\Support\RestNormalizers;
use LibreNMS\Util\DeviceApiSettings;

class FlashArrayClient implements DeviceApiClientInterface
{
    public const VENDOR = 'purestorage';

    protected DeviceHttpClient $http;

    public function __construct(Device $device)
    {
        $httpOpts = DeviceApiSettings::httpOptions($device);
        $pureOpts = DeviceApiSettings::pureOptions($device);
        // Build base URL with version if you want (e.g., /api/2.26). You can also negotiate.
        $base = rtrim($httpOpts['base_url'], '/');
        $this->http = new DeviceHttpClient([
            'base_url'   => $base,
            'headers'    => array_merge($httpOpts['headers'] ?? [], ['X-Auth-Token' => $pureOpts['token'] ?? '']),
            'verify_tls' => $httpOpts['verify_tls'],
            'timeout_ms' => $httpOpts['timeout_ms'],
            'proxy'      => $httpOpts['proxy'],
        ]);
    }

    public function supports(Device $device): bool
    {
        $vendor = DeviceApiSettings::vendor($device);
        if ($vendor === self::VENDOR) { return true; }
        // Probe example: GET arrays or hosts and check shape
        try {
            $arrays = $this->http->get('api/2.26/arrays');
            return is_array($arrays);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function capabilities(): array
    {
        return ['sensors','ports','inventory'];
    }

    public function fetchSensors(Device $device): array
    {
        $array = $this->http->get('api/2.26/arrays');
        $perf  = $this->http->get('api/2.26/array/performance');
        $hw    = $this->http->get('api/2.26/hardware');
        $hosts = $this->http->get('api/2.26/hosts');

        $out = [];
        $out = array_merge($out, RestNormalizers::normalizePureArraySensors($array, $perf));
        $hwMapped = RestNormalizers::normalizePureHardware($hw);
        $out = array_merge($out, $hwMapped['sensors'] ?? []);
        $hostsMapped = RestNormalizers::normalizePureHosts($hosts);
        $out = array_merge($out, $hostsMapped['sensors'] ?? []);
        return $out;
    }

    public function fetchPorts(Device $device): array
    {
        $ifaces = $this->http->get('api/2.26/network-interfaces');
        // Optionally also fetch performance: $perf = $this->http->get('api/2.26/network-performance');
        return RestNormalizers::normalizePureNetworkInterfaces($ifaces);
    }

    public function fetchMempools(Device $device): array { return []; }
    public function fetchProcessors(Device $device): array { return []; }

    public function fetchInventory(Device $device): array
    {
        $hw    = $this->http->get('api/2.26/hardware');
        $drives = $this->http->get('api/2.26/drives'); // if available
        $hosts = $this->http->get('api/2.26/hosts');

        $out = [];
        $hwMapped = RestNormalizers::normalizePureHardware($hw);
        $out = array_merge($out, $hwMapped['inventory'] ?? []);
        // If you have a dedicated normalizer for drives, include it here
        $hostsMapped = RestNormalizers::normalizePureHosts($hosts);
        $out = array_merge($out, $hostsMapped['inventory'] ?? []);
        return $out;
    }
}