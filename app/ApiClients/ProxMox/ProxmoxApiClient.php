<?php
namespace App\ApiClients\Proxmox;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

class ProxmoxApiClient
{
    protected string $base;
    protected int $timeout;
    protected bool $verifyTls;
    protected ?string $proxy;
    protected string $authType;
    protected array $headers = [];
    protected array $cookies = [];

    public function __construct(Device $device)
    {
        $a = $device->attribs ?? [];
        $this->base = rtrim($a['proxmox_base_url'] ?? '', '/');
        $this->timeout = (int)($a['proxmox_timeout_ms'] ?? 5000);
        $this->verifyTls = (bool)($a['proxmox_verify_tls'] ?? true);
        $this->proxy = $a['proxmox_proxy'] ?? null;
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
            ->post($this->base . '/api2/json/access/ticket', ['username' => $user, 'password' => $password]);

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

    protected function request(string $path): array
    {
        $req = Http::withHeaders($this->headers)
            ->withCookies($this->cookies, parse_url($this->base, PHP_URL_HOST))
            ->timeout($this->timeout / 1000)
            ->withOptions(['verify' => $this->verifyTls]);
        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        $resp = $req->get($this->base . '/api2/json/' . ltrim($path, '/'));
        if ($resp->failed()) {
            throw new \RuntimeException('Proxmox GET ' . $path . ' failed: ' . $resp->status());
        }
        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    public function getNodes(): array { return $this->request('nodes'); }
    public function getNodeStatus(string $node): array { return $this->request("nodes/{$node}/status"); }
    public function getNodeNetwork(string $node): array { return $this->request("nodes/{$node}/network"); }
    public function getClusterStatus(): array { return $this->request('cluster/status'); }
}