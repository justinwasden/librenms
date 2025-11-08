<?php

namespace App\ApiClients;

use App\Models\Device;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use LibreNMS\HTTP\RateLimiter;
use LibreNMS\Util\DeviceApiSettings;
use Illuminate\Support\Facades\Log;

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
    protected array $curlOptions = [];

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
        $this->curlOptions = $options['curl_options'] ?? [];

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
            ->withOptions(array_merge(
                ['verify' => $this->verifyTls],
                $this->proxy ? ['proxy' => $this->proxy] : [],
                $this->curlOptions ?? []
            ));

        // Attach cookies if provided via special header key
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
            return $req->withHeaders(['Content-Type' => 'application/json'])
                ->post($url . $this->querySuffix($query), $json);
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
        return $code >= 500 && $code <= 599;
    }

    protected function safeBodyPreview(Response $resp, int $maxLen = 256): string
    {
        $raw = (string) $resp->body();
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
     * Set a single header (mutates current instance for session management)
     */
    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    /**
     * Remove a single header (mutates current instance for session management)
     */
    public function unsetHeader(string $name): void
    {
        unset($this->headers[$name]);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function withCookies(array $cookies): self
    {
        $clone = clone $this;
        $clone->headers['_cookies'] = $cookies;
        return $clone;
    }

    public static function fromOptions(array $options, ?Device $device = null): self
    {
        return new self($options, $device);
    }

    protected function checkCircuitBreaker(): void
    {
        if (!$this->enableCircuitBreaker || !$this->device) {
            return;
        }

        if (DeviceApiSettings::shouldTripCircuitBreaker($this->device, $this->circuitBreakerThreshold)) {
            throw new \RuntimeException('Circuit breaker open: too many consecutive API failures. Reset via device settings.');
        }
    }

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

    protected function recordSuccess(float $start): void
    {
        if (!$this->device) {
            return;
        }

        $latencyMs = (int) ((microtime(true) - $start) * 1000);
        DeviceApiSettings::recordSuccess($this->device, $latencyMs);
    }

    protected function recordError(string $error): void
    {
        if (!$this->device) {
            return;
        }

        DeviceApiSettings::recordError($this->device, $error);
    }

    public function isReachable(): bool
    {
        try {
            $this->get('/');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getApiInfo(): array
    {
        return [
            'vendor' => 'generic',
            'base_url' => $this->baseUrl,
            'version' => 'unknown',
        ];
    }

    public static function fromDevice(Device $device): self
    {
        $options = DeviceApiSettings::httpOptions($device);
        $options['rate_limit_qps'] = DeviceApiSettings::rateLimitQps($device);

        return new self($options, $device);
    }

    public function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->curlOptions = $options;
        return $clone;
    }

    /**
     * Raw helpers to access headers and status for endpoints that return auth data in headers.
     */
    public function rawGet(string $path, array $query = []): array
    {
        $resp = $this->send('GET', $path, ['query' => $query]);
        return [
            'status'  => $resp->status(),
            'headers' => $resp->headers(),
            'json'    => $this->parseJson($resp, $path),
            'body'    => $this->safeBodyPreview($resp),
        ];
    }

    public function rawPost(string $path, array $body = [], array $query = []): array
    {
        $resp = $this->send('POST', $path, ['json' => $body, 'query' => $query]);
        return [
            'status'  => $resp->status(),
            'headers' => $resp->headers(),
            'json'    => $this->parseJson($resp, $path),
            'body'    => $this->safeBodyPreview($resp),
        ];
    }
}