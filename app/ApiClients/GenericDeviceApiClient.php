<?php

namespace App\ApiClients;

use App\Models\Device;
use App\Models\DeviceApiConfig;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use LibreNMS\Util\DeviceApiSettings;

class GenericDeviceApiClient implements DeviceApiClientInterface
{
    protected Device $device;
    protected DeviceHttpClient $httpClient;
    protected DeviceApiConfig $apiConfig;

    public function __construct(Device $device)
    {
        $this->device = $device;

        // Load API config
        $this->apiConfig = $device->apiConfig ?? DeviceApiConfig::with('template', 'schema')
            ->where('device_id', $device->device_id)
            ->firstOrFail();

        // Build HTTP client with auth
        $httpOptions = DeviceApiSettings::httpOptions($device);
        if (empty($httpOptions['base_url'])) {
            throw new \RuntimeException("No base URL configured for device {$device->device_id}");
        }

        $headers = $this->buildAuthHeaders();

        $this->httpClient = new DeviceHttpClient([
            'base_url' => $httpOptions['base_url'],
            'headers' => array_merge($httpOptions['headers'] ?? [], $headers),
            'verify_tls' => $httpOptions['verify_tls'] ?? true,
            'timeout_ms' => $httpOptions['timeout_ms'] ?? 5000,
            'proxy' => $httpOptions['proxy'] ?? null,
        ], $device);

        // Initialize session-based auth if needed
        if ($this->apiConfig->schema?->key === 'vmware_vcenter_session') {
            $this->initializeVmwareSession();
        }
    }

    /**
     * Initialize VMware vCenter session authentication
     * Creates a session and updates the HTTP client headers with the session ID
     */
    protected function initializeVmwareSession(): void
    {
        $username = $this->apiConfig->getValue('username');
        $password = $this->apiConfig->getValue('password');

        if (!$username || $password === null) {
            throw new \RuntimeException("Username and password required for VMware vCenter session auth");
        }

        // Create a temporary HTTP client with Basic auth for session creation
        $httpOptions = DeviceApiSettings::httpOptions($this->device);
        $tempClient = new DeviceHttpClient([
            'base_url' => $this->httpClient->getBaseUrl(),
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("$username:$password"),
                'Accept'        => 'application/json',
            ],
            'verify_tls' => $httpOptions['verify_tls'] ?? true,
            'timeout_ms' => $httpOptions['timeout_ms'] ?? 5000,
            'proxy' => $httpOptions['proxy'] ?? null,
        ], $this->device);

        // Try modern endpoint first: POST /api/session (session id in header)
        try {
            $raw = $tempClient->rawPost('/api/session', []);
            $headers = array_change_key_case($raw['headers'] ?? [], CASE_LOWER);
            $sessionId = null;

            if (isset($headers['vmware-api-session-id'])) {
                $h = $headers['vmware-api-session-id'];
                $sessionId = is_array($h) ? ($h[0] ?? null) : $h;
            }

            // Some environments return JSON
            if (!$sessionId && is_array($raw['json']) && isset($raw['json']['value'])) {
                $sessionId = $raw['json']['value'];
            }

            if ($sessionId) {
                $this->httpClient->setHeader('vmware-api-session-id', $sessionId);
                return;
            }

            $code = (int) ($raw['status'] ?? 0);
            if (!in_array($code, [404, 405], true)) {
                throw new \RuntimeException('Modern /api/session did not return session id');
            }
        } catch (\Throwable $e) {
            // Fall back only for clear 404/405
            $msg = $e->getMessage();
            $code = 0;
            if (preg_match('/returned\s+(\d{3})/i', $msg, $m)) {
                $code = (int) $m[1];
            }
            if ($code && !in_array($code, [404, 405], true)) {
                throw new \RuntimeException("Modern /api/session failed: {$msg}", $code, $e);
            }
        }

        // Legacy: POST /rest/com/vmware/cis/session (JSON {"value": "..."} )
        $resp = $tempClient->post('/rest/com/vmware/cis/session', []);
        $sessionId = $resp['value'] ?? null;
        if (!$sessionId) {
            throw new \RuntimeException("Legacy /rest session failed or missing value: " . json_encode($resp));
        }
        $this->httpClient->setHeader('vmware-api-session-id', $sessionId);
    }

    public function supports(Device $device): bool
    {
        // This is a fallback client - it supports any device with an API config
        $apiConfig = $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();
        return $apiConfig !== null;
    }

    public function capabilities(): array
    {
        // Return capabilities from template
        $tpl = $this->apiConfig->template;
        if (!$tpl) {
            return [];
        }
        $capabilities = $tpl->capabilities ?? [];
        return is_array($capabilities) ? $capabilities : (json_decode($capabilities, true) ?: []);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->httpClient->get($path, $query);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->httpClient->post($path, $body);
    }

    public function fetchSensors(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchPorts(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchMempools(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchProcessors(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchInventory(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchStorage(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchTransceivers(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchIpv4Addresses(\App\Models\Device $device): array
    {
        return [];
    }

    public function fetchPortsStatistics(\App\Models\Device $device): array
    {
        return [];
    }

    public function isReachable(): bool
    {
        try {
            $this->httpClient->get('/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        return [
            'vendor' => 'generic',
            'client' => 'GenericDeviceApiClient',
            'template_key' => $this->apiConfig->template?->key ?? null,
        ];
    }

    protected function buildAuthHeaders(): array
    {
        $headers = [];
        $schema = $this->apiConfig->schema;

        if (!$schema) {
            return $headers;
        }

        switch ($schema->key) {
            case 'basic':
                // Basic authentication
                $username = $this->apiConfig->getValue('username') ?? '';
                $password = $this->apiConfig->getValue('password') ?? '';
                if ($username !== '') {
                    $encoded = base64_encode("$username:$password");
                    $headers['Authorization'] = "Basic $encoded";
                }
                break;

            case 'bearer':
                // Bearer token
                $token = $this->apiConfig->getValue('token') ?? $this->apiConfig->getValue('api_token') ?? '';
                if ($token) {
                    $headers['Authorization'] = "Bearer $token";
                }
                break;

            case 'vmware_vcenter_session':
                // VMware vCenter session-based auth
                // Session is initialized in constructor via initializeVmwareSession()
                break;

            case 'custom_header':
                // Custom header auth
                $headerName = $this->apiConfig->getValue('header_name') ?? '';
                $headerValue = $this->apiConfig->getValue('header_value') ?? '';
                if ($headerName && $headerValue) {
                    $headers[$headerName] = $headerValue;
                }
                break;

            default:
                // Try to auto-detect from values
                $token = $this->apiConfig->getValue('token') ?? $this->apiConfig->getValue('api_token');
                if ($token) {
                    $headers['Authorization'] = "Bearer $token";
                } else {
                    $username = $this->apiConfig->getValue('username');
                    $password = $this->apiConfig->getValue('password') ?? '';
                    if (!empty($username)) {
                        $encoded = base64_encode($username . ':' . $password);
                        $headers['Authorization'] = "Basic $encoded";
                    }
                }
                break;
        }

        // Add any custom headers from config
        $customHeaders = $this->apiConfig->getValue('custom_headers');
        if (!empty($customHeaders) && is_array($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }

        return $headers;
    }
}