<?php

namespace App\ApiClients;

use App\Models\Device;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LibreNMS\HTTP\RateLimiter;
use LibreNMS\Util\DeviceApiSettings;

/**
 * Generic HTTP client for device APIs.
 * - Base URL handling
 * - Common headers (auth, custom)
 * - TLS verification, timeout, proxy
 * - Retries with exponential backoff
 * - JSON parsing and error normalization
 *
 * Compose this inside vendor-specific clients (e.g., PureStorage, Proxmox).
 */
class DeviceHttpClient
{
    protected string $baseUrl;
    protected array $headers;
    protected bool $verifyTls;
    protected int $timeoutMs;
    protected ?string $proxy;
    protected int $maxRetries;
    protected int $retryInitialDelayMs;
    protected ?Device $device;
    protected ?RateLimiter $rateLimiter;
    protected int $rateLimitQps;
    protected bool $enableCircuitBreaker;
    protected int $circuitBreakerThreshold;

    public function __construct(array $options, ?Device $device = null)
    {
        $this->baseUrl = rtrim((string)($options['base_url'] ?? ''), '/');
        $this->headers = (array)($options['headers'] ?? []);
        $this->verifyTls = (bool)($options['verify_tls'] ?? true);
        $this->timeoutMs = (int)($options['timeout_ms'] ?? 5000);
        $this->proxy = $options['proxy'] ?? null;
        $this->maxRetries = (int)($options['max_retries'] ?? 2);
        $this->retryInitialDelayMs = (int)($options['retry_initial_delay_ms'] ?? 250);
        $this->device = $device;
        $this->rateLimiter = $options['rate_limiter'] ?? app(RateLimiter::class);
        $this->rateLimitQps = (int)($options['rate_limit_qps'] ?? 10);
        $this->enableCircuitBreaker = (bool)($options['enable_circuit_breaker'] ?? true);
        $this->circuitBreakerThreshold = (int)($options['circuit_breaker_threshold'] ?? 5);

        if ($this->baseUrl === '') {
            throw new \InvalidArgumentException('DeviceHttpClient requires base_url');
        }
    }

    /**
     * HTTP GET returning decoded JSON array.
     */
    public function get(string $path, array $query = []): array
    {
        $this->checkCircuitBreaker();
        $this->applyRateLimit();

        $start = microtime(true);
        try {
            $resp = $this->send('GET', $path, ['query' => $query]);
            $data = $this->parseJson($resp, $path);
            $this->recordSuccess($start);
            return $data;
        } catch (\Throwable $e) {
            $this->recordError($e->getMessage());
            throw $e;
        }
    }

    /**
     * HTTP POST returning decoded JSON array.
     * $body is sent as JSON by default.
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        $this->checkCircuitBreaker();
        $this->applyRateLimit();

        $start = microtime(true);
        try {
            $resp = $this->send('POST', $path, ['json' => $body, 'query' => $query]);
            $data = $this->parseJson($resp, $path);
            $this->recordSuccess($start);
            return $data;
        } catch (\Throwable $e) {
            $this->recordError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Core request sender with retries/backoff.
     */
    protected function send(string $method, string $path, array $opts = []): Response
    {
        $url = $this->buildUrl($path);

        $req = Http::withHeaders($this->headers)
            ->timeout($this->timeoutMs / 1000)
            ->withOptions(['verify' => $this->verifyTls]);

        if ($this->proxy) {
            $req = $req->withOptions(['proxy' => $this->proxy]);
        }

        // Attach cookies if provided in headers via special key
        // Example: $options['cookies'] = ['Name' => 'Value'] set in headers via setCookies()
        if (!empty($this->headers['_cookies']) && is_array($this->headers['_cookies'])) {
            $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '';
            $req = $req->withCookies($this->headers['_cookies'], $host);
        }

        $attempt = 0;
        $delay = $this->retryInitialDelayMs;

        while (true) {
            $attempt++;

            try {
                $resp = $this->dispatch($req, $method, $url, $opts);

                // Retry on transient errors (HTTP 429/5xx)
                if ($this->shouldRetry($resp) && $attempt <= $this->maxRetries + 1) {
                    usleep($delay * 1000);
                    $delay = min($delay * 2, 2000);
                    continue;
                }

                return $resp;
            } catch (\Throwable $e) {
                // Network/timeout/transport exceptions should retry
                if ($attempt <= $this->maxRetries + 1) {
                    usleep($delay * 1000);
                    $delay = min($delay * 2, 2000);
                    continue;
                }
                throw new \RuntimeException(sprintf('HTTP %s %s failed: %s', $method, $url, $e->getMessage()), 0, $e);
            }
        }
    }

    protected function dispatch($req, string $method, string $url, array $opts): Response
    {
        // Query params
        $query = Arr::get($opts, 'query', []);
        // Body options
        $json = Arr::get($opts, 'json', null);
        $form = Arr::get($opts, 'form_params', null);

        if (strtoupper($method) === 'GET') {
            return $req->get($url, $query);
        }

        if ($json !== null) {
            return $req->withHeaders(['Content-Type' => 'application/json'])->post($url . $this->querySuffix($query), $json);
        }

        if ($form !== null) {
            return $req->asForm()->post($url . $this->querySuffix($query), $form);
        }

        // Default POST without body
        return $req->post($url . $this->querySuffix($query));
    }

    protected function parseJson(Response $resp, string $path): array
    {
        if ($resp->failed()) {
            $status = $resp->status();
            $body = $this->safeBodyPreview($resp);
            throw new \RuntimeException(sprintf('HTTP %s returned %d: %s', $path, $status, $body));
        }

        $data = $resp->json();

        if (is_null($data)) {
            // Non-JSON or empty body; treat as empty array
            return [];
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response for ' . $path);
        }

        return $data;
    }

    protected function buildUrl(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    protected function querySuffix(array $query): string
    {
        if (empty($query)) {
            return '';
        }
        return '?' . http_build_query($query);
    }

    protected function shouldRetry(Response $resp): bool
    {
        $code = $resp->status();
        if ($code === 429) {
            return true;
        }
        // Retry on 5xx server errors
        return $code >= 500 && $code <= 599;
    }

    protected function safeBodyPreview(Response $resp, int $maxLen = 256): string
    {
        $raw = (string) $resp->body();
        // Avoid logging secrets; truncate and strip newlines
        $raw = preg_replace('/\s+/', ' ', $raw);
        return mb_substr($raw, 0, $maxLen);
    }

    /**
     * Helper to add or override headers (e.g., auth) at runtime.
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);
        return $clone;
    }

    /**
     * Helper to set cookies, e.g., Proxmox ticket auth.
     * Usage: $client->withCookies(['PVEAuthCookie' => $ticket])
     */
    public function withCookies(array $cookies): self
    {
        $clone = clone $this;
        $clone->headers['_cookies'] = $cookies;
        return $clone;
    }

    /**
     * Factory convenience to build client from a generic options array.
     * Expected keys:
     *  - base_url (string)
     *  - headers (array)
     *  - verify_tls (bool)
     *  - timeout_ms (int)
     *  - proxy (string|null)
     *  - max_retries (int)
     *  - retry_initial_delay_ms (int)
     */
    public static function fromOptions(array $options, ?Device $device = null): self
    {
        return new self($options, $device);
    }

    /**
     * Check circuit breaker state before making requests
     *
     * @throws \RuntimeException If circuit breaker is tripped
     */
    protected function checkCircuitBreaker(): void
    {
        if (!$this->enableCircuitBreaker || !$this->device) {
            return;
        }

        if (DeviceApiSettings::shouldTripCircuitBreaker($this->device, $this->circuitBreakerThreshold)) {
            throw new \RuntimeException('Circuit breaker open: too many consecutive API failures. Reset via device settings.');
        }
    }

    /**
     * Apply rate limiting before making requests
     */
    protected function applyRateLimit(): void
    {
        if (!$this->rateLimiter) {
            return;
        }

        $key = $this->baseUrl;
        if (!$this->rateLimiter->waitForAllow($key, $this->rateLimitQps)) {
            throw new \RuntimeException('Rate limit timeout exceeded');
        }
    }

    /**
     * Record successful API call with latency tracking
     */
    protected function recordSuccess(float $start): void
    {
        if (!$this->device) {
            return;
        }

        $latencyMs = (int) ((microtime(true) - $start) * 1000);
        DeviceApiSettings::recordSuccess($this->device, $latencyMs);
    }

    /**
     * Record failed API call
     */
    protected function recordError(string $error): void
    {
        if (!$this->device) {
            return;
        }

        DeviceApiSettings::recordError($this->device, $error);
    }

    /**
     * Test if the API is reachable with a simple request
     *
     * @return bool True if API is reachable and returns valid response
     */
    public function isReachable(): bool
    {
        try {
            // Try a simple GET to the base URL or a known lightweight endpoint
            $this->get('/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get API information (override in vendor-specific clients)
     *
     * @return array Basic info about the API
     */
    public function getApiInfo(): array
    {
        return [
            'vendor' => 'generic',
            'base_url' => $this->baseUrl,
            'version' => 'unknown',
        ];
    }

    /**
     * Factory method to create client from Device model
     *
     * @param Device $device
     * @return static
     */
    public static function fromDevice(Device $device): self
    {
        $options = DeviceApiSettings::httpOptions($device);
        $options['rate_limit_qps'] = DeviceApiSettings::rateLimitQps($device);

        return new self($options, $device);
    }
}
