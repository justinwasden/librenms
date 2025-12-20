<?php

namespace App\ApiClients;

use App\Models\Device;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use App\ApiClients\DeviceHttpClient;
use LibreNMS\Util\DeviceApiSettings;

class GenericDeviceApiClient implements DeviceApiClientInterface
{
    protected Device $device;
    protected DeviceHttpClient $httpClient;

    public function __construct(Device $device)
    {
        $this->device = $device;

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
        $schemaKey = $device->getAttrib('api_auth_schema');
        if ($schemaKey === 'vmware_vcenter_session') {
            $this->initializeVmwareSession();
        }
    }

    /**
     * Initialize VMware vCenter session authentication
     * Creates a session and updates the HTTP client headers with the session ID
     */
    protected function initializeVmwareSession(): void
    {
        $username = $this->device->getAttrib('api_credential_username');
        $password = $this->device->getAttrib('api_credential_password');

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
        return !empty($device->getAttrib('api_base_url'));
    }

    public function capabilities(): array
    {
        // Capabilities are no longer stored in templates
        // Return empty array - specific clients should override this
        return [];
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
            'template_key' => $this->device->getAttrib('api_template_key'),
        ];
    }

    protected function buildAuthHeaders(): array
    {
        $headers = [];

        // Get schema key from attributes
        $schemaKey = $this->device->getAttrib('api_auth_schema');

        if (!$schemaKey) {
            return $headers;
        }

        switch ($schemaKey) {
            case 'basic':
                // Basic authentication
                $username = $this->device->getAttrib('api_credential_username') ?? '';
                $password = $this->device->getAttrib('api_credential_password') ?? '';
                if ($username !== '') {
                    $encoded = base64_encode("$username:$password");
                    $headers['Authorization'] = "Basic $encoded";
                }
                break;

            case 'bearer':
                // Bearer token
                $token = $this->device->getAttrib('api_credential_token') ?? $this->device->getAttrib('api_credential_api_token') ?? '';
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
                $headerName = $this->device->getAttrib('api_credential_header_name') ?? '';
                $headerValue = $this->device->getAttrib('api_credential_header_value') ?? '';
                if ($headerName && $headerValue) {
                    $headers[$headerName] = $headerValue;
                }
                break;

            default:
                // Try to auto-detect from credentials
                $token = $this->device->getAttrib('api_credential_token') ?? $this->device->getAttrib('api_credential_api_token');
                if ($token) {
                    $headers['Authorization'] = "Bearer $token";
                } else {
                    $username = $this->device->getAttrib('api_credential_username');
                    $password = $this->device->getAttrib('api_credential_password') ?? '';
                    if (!empty($username)) {
                        $encoded = base64_encode($username . ':' . $password);
                        $headers['Authorization'] = "Basic $encoded";
                    }
                }
                break;
        }

        return $headers;
    }

    public function fetchVms(Device $device): array
    {
        // Generic clients don't support VM discovery by default
        return [];
    }
}