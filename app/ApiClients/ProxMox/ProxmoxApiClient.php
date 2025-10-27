<?php
namespace App\ApiClients\Proxmox;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

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

    public function __construct(Device $device)
    {
        $this->device = $device;
        $a = $device->attribs ?? [];
        $this->base = rtrim($a['proxmox_base_url'] ?? $a['rest_base_url'] ?? '', '/');
        $this->timeout = (int)($a['proxmox_timeout_ms'] ?? $a['rest_timeout_ms'] ?? 5000);
        $this->verifyTls = (bool)($a['proxmox_verify_tls'] ?? $a['rest_verify_tls'] ?? true);
        $this->proxy = $a['proxmox_proxy'] ?? $a['rest_proxy'] ?? null;
        $this->authType = $a['proxmox_auth_type'] ?? 'token';

        if ($this->authType === 'token') {
            $user = $a['proxmox_token_user'] ?? '';
            $tokenid = $a['proxmox_token_id'] ?? '';
            $secret = !empty($a['proxmox_token_enc']) ? Crypt::decryptString($a['proxmox_token_enc']) : '';
            $this->headers['Authorization'] = "PVEAPIToken={$user}!{$tokenid}={$secret}";
        } else {
            $this->login($a); // sets cookie/header
        }
    }

    protected function login(array $a): void
    {
        $user = $a['proxmox_username'] ?? '';
        $password = !empty($a['proxmox_password_enc']) ? Crypt::decryptString($a['proxmox_password_enc']) : '';
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