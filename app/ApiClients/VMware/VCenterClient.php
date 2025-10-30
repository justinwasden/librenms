<?php

namespace App\ApiClients\VMware;

use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use App\Models\Device;
use App\Models\DeviceApiConfig;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * VMware vCenter REST API Client
 */
class VCenterClient implements DeviceApiClientInterface
{
    public const VENDOR = 'vmware';

    public ?string $sessionId = null;

    protected Device $device;
    protected DeviceHttpClient $httpClient;
    protected ?DeviceApiConfig $apiConfig = null;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load saved API config from relation or DB
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();

        if (!$this->apiConfig) {
            throw new RuntimeException("No saved API configuration found for device {$device->device_id}");
        }

        // Use model columns for base_url/verify_ssl and values for other fields
        $baseUrl   = $this->apiConfig->base_url;
        $username  = $this->apiConfig->getValue('username');
        $password  = $this->apiConfig->getValue('password');
        $verifyTls = (bool) ($this->apiConfig->verify_ssl ?? true);
        $timeoutMs = (int) $this->apiConfig->getValue('timeout_ms', 5000);

        // Mask password for logs
        $maskedPassword = $password !== null ? str_repeat('*', max(4, strlen($password))) : null;

        Log::debug('VCenterClient init config', [
            'device_id'     => $this->device->device_id,
            'base_url'      => $baseUrl,
            'username'      => $username,
            'password'      => $maskedPassword,
            'verify_tls'    => $verifyTls,
            'timeout_ms'    => $timeoutMs,
            'extra_headers' => $this->apiConfig->extra_headers ?? [],
        ]);

        if (!$baseUrl) {
            throw new RuntimeException("API config for device {$device->device_id} is missing base_url");
        }

        // Initialize HTTP client
        $headers = $this->apiConfig->extra_headers ?? [];
        if ($username && $password) {
            $headers['Authorization'] = 'Basic ' . base64_encode("$username:$password");
        }

        $this->httpClient = new DeviceHttpClient([
            'base_url'   => rtrim($baseUrl, '/'),
            'headers'    => $headers,
            'verify_tls' => $verifyTls,
            'timeout_ms' => $timeoutMs,
        ], $device);

        Log::debug('VCenterClient headers before session', [
            'has_auth_header' => array_key_exists('Authorization', $headers),
            'headers' => array_keys($headers),
        ]);

        // Initialize VMware session automatically
        $this->initializeSession();
    }

    /**
     * Create VMware vCenter session and set session ID header
     */
    protected function initializeSession(): void
    {
        Log::debug('VCenterClient attempting session creation', [
            'device_id' => $this->device->device_id,
            'base_url' => $this->apiConfig->base_url,
        ]);

        // Try modern endpoint first: POST /api/session (session id in header)
        try {
            $raw = $this->httpClient->rawPost('/api/session', []);
            $headers = array_change_key_case($raw['headers'] ?? [], CASE_LOWER);
            $sessionId = null;

            if (isset($headers['vmware-api-session-id'])) {
                $h = $headers['vmware-api-session-id'];
                $sessionId = is_array($h) ? ($h[0] ?? null) : $h;
            }

            // Some environments return JSON with value as a fallback
            if (!$sessionId && is_array($raw['json']) && isset($raw['json']['value'])) {
                $sessionId = $raw['json']['value'];
            }

            Log::debug('VCenterClient /api/session result', [
                'status' => $raw['status'] ?? null,
                'has_session_id' => (bool) $sessionId,
            ]);

            if ($sessionId) {
                $this->sessionId = $sessionId;
                $this->httpClient->setHeader('vmware-api-session-id', $sessionId);
                Log::debug('VCenterClient session created', [
                    'device_id'  => $this->device->device_id,
                    'session_id' => substr($sessionId, 0, 8) . '...',
                ]);
                return;
            }

            // If modern endpoint did not provide a session, decide whether to fall back
            $code = (int) ($raw['status'] ?? 0);
            if (!in_array($code, [404, 405], true)) {
                throw new RuntimeException('Modern /api/session did not return session id');
            }
        } catch (\Throwable $e) {
            // Fall back only for clear 404/405 errors
            $code = 0;
            $msg = $e->getMessage();
            if (preg_match('/returned\s+(\d{3})/i', $msg, $m)) {
                $code = (int) $m[1];
            }
            Log::debug('VCenterClient /api/session failed', [
                'error' => $msg,
                'code' => $code,
                'class' => get_class($e),
            ]);

            if ($code && !in_array($code, [404, 405], true)) {
                throw new RuntimeException("Modern /api/session failed: " . $e->getMessage(), $code, $e);
            }
        }

        // Legacy: POST /rest/com/vmware/cis/session (JSON {"value": "..."} )
        $resp = $this->httpClient->post('/rest/com/vmware/cis/session', []);
        $sessionId = $resp['value'] ?? null;
        if (!$sessionId) {
            throw new RuntimeException("Legacy /rest session failed or missing value: " . json_encode($resp));
        }

        $this->sessionId = $sessionId;
        $this->httpClient->setHeader('vmware-api-session-id', $sessionId);

        Log::debug('VCenterClient legacy session created', [
            'device_id'  => $this->device->device_id,
            'session_id' => substr($sessionId, 0, 8) . '...',
        ]);
    }

    // -------- DeviceApiClientInterface required methods --------

    public function supports(Device $device): bool
    {
        return in_array($device->os, ['vmware', 'vsphere', 'vmware-vcsa'], true)
            && ($device->apiConfig !== null || $this->apiConfig !== null);
    }

    public function capabilities(): array
    {
        return ['sensors', 'ports', 'mempools', 'processors', 'inventory', 'ipv4'];
    }

    public function fetchSensors(Device $device): array
    {
        Log::debug('VCenterClient fetchSensors called (stub)', ['device_id' => $device->device_id]);
        return [];
    }

    public function fetchPorts(Device $device): array
    {
        Log::debug('VCenterClient fetchPorts called (stub)', ['device_id' => $device->device_id]);
        return [];
    }

    public function fetchMempools(Device $device): array
    {
        Log::debug('VCenterClient fetchMempools called (stub)', ['device_id' => $device->device_id]);
        return [];
    }

    public function fetchProcessors(Device $device): array
    {
        Log::debug('VCenterClient fetchProcessors called (stub)', ['device_id' => $device->device_id]);
        return [];
    }

    public function fetchInventory(Device $device): array
    {
        Log::debug('VCenterClient fetchInventory called (stub)', ['device_id' => $device->device_id]);
        return [];
    }

    public function fetchIpv4Addresses(Device $device): array
    {
        Log::debug('VCenterClient fetchIpv4Addresses called (stub)', ['device_id' => $device->device_id]);
        return [];
    }

    /**
     * Low-level HTTP transport methods
     */
    public function get(string $path, array $query = []): array
    {
        Log::debug('VCenterClient GET', ['path' => $path, 'query' => $query]);
        return $this->httpClient->get($path, $query);
    }

    public function post(string $path, array $body = []): array
    {
        Log::debug('VCenterClient POST', ['path' => $path, 'body' => $body]);
        return $this->httpClient->post($path, $body);
    }

    /**
     * Reachability check
     */
    public function isReachable(): bool
    {
        try {
            // Lightweight ping: session endpoint
            $raw = $this->httpClient->rawGet('/api/session');
            return (int) ($raw['status'] ?? 0) < 500;
        } catch (\Throwable $e) {
            Log::debug('VCenterClient reachability check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get API version and metadata
     */
    public function getApiInfo(): array
    {
        try {
            $info = [
                'vendor'      => self::VENDOR,
                'base_url'    => $this->apiConfig->base_url ?? null,
                'api_version' => null,
                'version'     => null,
            ];

            // Attempt a version probe (may not exist on all versions)
            try {
                $resp = $this->get('/appliance/system/version');
                if (is_array($resp)) {
                    $info['version'] = $resp['version'] ?? ($resp['value']['version'] ?? null);
                    $info['api_version'] = $resp['api_version'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::debug('VCenterClient getApiInfo version probe failed', ['error' => $e->getMessage()]);
            }

            return $info;
        } catch (\Throwable $e) {
            Log::error('VCenterClient getApiInfo failed', ['error' => $e->getMessage()]);
            return [
                'vendor'      => self::VENDOR,
                'base_url'    => $this->apiConfig->base_url ?? null,
                'api_version' => null,
                'version'     => null,
                'error'       => $e->getMessage(),
            ];
        }
    }
}