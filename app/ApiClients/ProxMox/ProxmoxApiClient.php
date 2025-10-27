<?php
namespace App\ApiClients\Proxmox;

use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Http;
use LibreNMS\Util\DeviceApiSettings;

class ProxmoxApiClient
{
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
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        // Get HTTP options from DeviceApiSettings
        $http = DeviceApiSettings::httpOptions($device);
        $this->base = rtrim($http['base_url'], '/');
        $this->timeout = (int)$http['timeout_ms'];
        $this->verifyTls = (bool)$http['verify_tls'];
        $this->proxy = $http['proxy'] ?? null;

        // Determine auth type from schema
        $this->authType = $this->apiConfig?->getValue('auth_type') ?? 'token';

        if ($this->authType === 'token') {
            $user = $this->apiConfig?->getValue('token_user') ?? '';
            $tokenid = $this->apiConfig?->getValue('token_id') ?? '';
            $secret = $this->apiConfig?->getValue('token_secret') ?? '';
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
            throw new \RuntimeException("Proxmox GET $path failed: " . $resp->status());
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
}