<?php

namespace App\ApiClients;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

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

    public function __construct(array $options)
    {
        $this->baseUrl = rtrim((string)($options['base_url'] ?? ''), '/');
        $this->headers = (array)($options['headers'] ?? []);
        $this->verifyTls = (bool)($options['verify_tls'] ?? true);
        $this->timeoutMs = (int)($options['timeout_ms'] ?? 5000);
        $this->proxy = $options['proxy'] ?? null;
        $this->maxRetries = (int)($options['max_retries'] ?? 2);
        $this->retryInitialDelayMs = (int)($options['retry_initial_delay_ms'] ?? 250);

        if ($this->baseUrl === '') {
            throw new \InvalidArgumentException('DeviceHttpClient requires base_url');
        }
    }

    /**
     * HTTP GET returning decoded JSON array.
     */
    public function get(string $path, array $query = []): array
    {
        $resp = $this->send('GET', $path, ['query' => $query]);
        return $this->parseJson($resp, $path);
    }

    /**
     * HTTP POST returning decoded JSON array.
     * $body is sent as JSON by default.
     */
    public function post(string $path, array $body = [], array $query = []): array
    {
        $resp = $this->send('POST', $path, ['json' => $body, 'query' => $query]);
        return $this->parseJson($resp, $path);
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
    public static function fromOptions(array $options): self
    {
        return new self($options);
    }
}
