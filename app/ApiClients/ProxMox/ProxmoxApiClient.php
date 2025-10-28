<?php
namespace App\ApiClients\Proxmox;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceApiSettings;

class ProxmoxApiClient implements DeviceApiClientInterface
{
    public const VENDOR = 'proxmox';
    protected Device $device;
    protected string $base;
    protected int $timeout;
    protected bool $verifyTls;
    protected ?string $proxy;
    protected string $authType;
    protected array $headers = [];
    protected array $cookies = [];
    protected ?DeviceApiConfig $apiConfig = null;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load API config from database
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::with('schema.fields')->where('device_id', $device->device_id)->first();

        // Get HTTP options from DeviceApiSettings
        $http = DeviceApiSettings::httpOptions($device);
        $this->base = rtrim($http['base_url'], '/');
        $this->timeout = (int)$http['timeout_ms'];
        $this->verifyTls = (bool)$http['verify_tls'];
        $this->proxy = $http['proxy'] ?? null;

        // Determine auth type from schema key
        $schemaKey = $this->apiConfig?->schema?->key ?? 'proxmox_token';
        $this->authType = str_contains($schemaKey, 'ticket') ? 'ticket' : 'token';

        if ($this->authType === 'token') {
            $user = $this->apiConfig?->getValue('token_user') ?? '';
            $tokenid = $this->apiConfig?->getValue('token_id') ?? '';
            $secret = $this->apiConfig?->getValue('token_secret') ?? '';

            // Validate required token fields
            if (empty($user) || empty($tokenid) || empty($secret)) {
                throw new \RuntimeException('Proxmox API token authentication requires token_user, token_id, and token_secret');
            }

            $this->headers['Authorization'] = "PVEAPIToken={$user}!{$tokenid}={$secret}";
        } else {
            $this->login(); // sets cookie/header
        }
    }

    protected function login(): void
    {
        $user = $this->apiConfig?->getValue('username') ?? '';
        $password = $this->apiConfig?->getValue('password') ?? '';

        $resp = Http::timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls])
            ->post($this->base . '/access/ticket', ['username' => $user, 'password' => $password]);

        if ($resp->failed()) {
            throw new \RuntimeException('Proxmox login failed: ' . $resp->status());
        }
        $data = $resp->json()['data'] ?? [];
        $ticket = $data['ticket'] ?? '';
        $csrf = $data['CSRFPreventionToken'] ?? '';
        $this->cookies = ['PVEAuthCookie' => $ticket];
        if ($csrf) {
            $this->headers['CSRFPreventionToken'] = $csrf;
        }
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::withHeaders($this->headers)
            ->withCookies($this->cookies, parse_url($this->base, PHP_URL_HOST))
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        return $req;
    }

    public function get(string $path, array $query = []): array
    {
        $uri = rtrim($this->base, '/') . '/' . ltrim($path, '/');
        $resp = $this->http()->get($uri, $query);
        if ($resp->failed()) {
            $body = $resp->body();
            $errorDetail = $body ? " - Response: $body" : '';
            throw new \RuntimeException("Proxmox GET $path failed: " . $resp->status() . $errorDetail);
        }
        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    public function post(string $path, array $body = []): array
    {
        $uri = rtrim($this->base, '/') . '/' . ltrim($path, '/');
        $resp = $this->http()->post($uri, $body);
        if ($resp->failed()) {
            throw new \RuntimeException("Proxmox POST $path failed: " . $resp->status());
        }
        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    // Optional helpers (not required by executor but useful)
    public function getNodes(): array { return $this->get('nodes'); }
    public function getNodeStatus(string $node): array { return $this->get("nodes/{$node}/status"); }
    public function getNodeNetwork(string $node): array { return $this->get("nodes/{$node}/network"); }
    public function getClusterStatus(): array { return $this->get('cluster/status'); }

    public function supports(Device $device): bool
    {
        return $device->os === 'proxmox' && $this->apiConfig !== null;
    }

    public function capabilities(): array
    {
        return ['sensors', 'ports', 'processors', 'mempools'];
    }

    public function fetchSensors(Device $device): array
    {
        // TODO: Implement sensor fetching
        return [];
    }

    public function fetchPorts(Device $device): array
    {
        // TODO: Implement port fetching
        return [];
    }

    public function fetchMempools(Device $device): array
    {
        // TODO: Implement mempool fetching
        return [];
    }

    public function fetchProcessors(Device $device): array
    {
        // TODO: Implement processor fetching
        return [];
    }

    public function fetchInventory(Device $device): array
    {
        return [];
    }

    public function fetchIpv4Addresses(Device $device): array
    {
        return [];
    }

    public function isReachable(): bool
    {
        try {
            $this->get('cluster/status');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        try {
            $data = $this->get('version');
            return [
                'vendor' => 'proxmox',
                'api_version' => $data['data']['version'] ?? 'unknown',
                'reachable' => true,
            ];
        } catch (\Exception $e) {
            return [
                'vendor' => 'proxmox',
                'reachable' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}