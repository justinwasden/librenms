<?php

namespace LibreNMS\HTTP;

/**
 * Token bucket rate limiter for API clients
 * 
 * Implements a per-key rate limiting strategy using the token bucket algorithm.
 * Each key (typically a base URL or device ID) gets its own bucket that refills
 * at a specified rate.
 */
class RateLimiter
{
    /**
     * @var array<string, array{tokens: float, last: float}> Token buckets keyed by identifier
     */
    protected array $buckets = [];

    /**
     * Check if a request is allowed under the rate limit
     *
     * @param string $key Unique identifier (e.g., base URL or device ID)
     * @param int $qps Queries per second allowed
     * @param float $burstMultiplier Allow bursting up to qps * burstMultiplier tokens
     * @return bool True if request is allowed, false if rate limited
     */
    public function allow(string $key, int $qps, float $burstMultiplier = 2.0): bool
    {
        $now = microtime(true);
        $maxTokens = $qps * $burstMultiplier;

        // Initialize bucket if it doesn't exist
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [
                'tokens' => $maxTokens,
                'last' => $now,
            ];
        }

        $bucket = &$this->buckets[$key];

        // Refill tokens based on elapsed time
        $elapsed = $now - $bucket['last'];
        $bucket['tokens'] = min($maxTokens, $bucket['tokens'] + ($elapsed * $qps));
        $bucket['last'] = $now;

        // Check if we have at least one token available
        if ($bucket['tokens'] >= 1.0) {
            $bucket['tokens'] -= 1.0;
            return true;
        }

        return false;
    }

    /**
     * Wait until a request is allowed (blocking)
     *
     * @param string $key Unique identifier
     * @param int $qps Queries per second allowed
     * @param float $burstMultiplier Burst multiplier
     * @param int $maxWaitMs Maximum time to wait in milliseconds (default 5000)
     * @return bool True if allowed after waiting, false if timeout
     */
    public function waitForAllow(string $key, int $qps, float $burstMultiplier = 2.0, int $maxWaitMs = 5000): bool
    {
        $start = microtime(true);
        $maxWaitSeconds = $maxWaitMs / 1000;

        while (!$this->allow($key, $qps, $burstMultiplier)) {
            if ((microtime(true) - $start) > $maxWaitSeconds) {
                return false;
            }
            usleep(50000); // Sleep 50ms between checks
        }

        return true;
    }

    /**
     * Get the current token count for a key
     *
     * @param string $key Unique identifier
     * @return float Current number of tokens (0 if bucket doesn't exist)
     */
    public function getTokens(string $key): float
    {
        if (!isset($this->buckets[$key])) {
            return 0.0;
        }

        $now = microtime(true);
        $bucket = $this->buckets[$key];
        $elapsed = $now - $bucket['last'];
        
        return $bucket['tokens'] + $elapsed;
    }

    /**
     * Reset a specific bucket
     *
     * @param string $key Unique identifier
     * @return void
     */
    public function reset(string $key): void
    {
        unset($this->buckets[$key]);
    }

    /**
     * Reset all buckets
     *
     * @return void
     */
    public function resetAll(): void
    {
        $this->buckets = [];
    }
}
