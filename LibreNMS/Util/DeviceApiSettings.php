<?php
namespace LibreNMS\Util;

use App\Models\Device;

class DeviceApiSettings
{
    public static function restEnabled(Device $device): bool
    {
        return $device->getAttrib('api_enabled') || $device->getAttrib('api_base_url') !== null;
    }

    public static function vendor(Device $device): ?string
    {
        // Return the OS as vendor identifier
        return $device->os;
    }

    public static function httpOptions(Device $device): array
    {
        // Read from device attributes
        $baseUrl = $device->getAttrib('api_base_url');

        if (!$baseUrl) {
            // Return defaults if no config exists
            return [
                'base_url'   => '',
                'verify_tls' => true,
                'timeout_ms' => 10000,
                'proxy'      => null,
                'headers'    => [],
            ];
        }

        $extraHeadersJson = $device->getAttrib('api_extra_headers');
        $headers = $extraHeadersJson ? (json_decode($extraHeadersJson, true) ?? []) : [];

        return [
            'base_url'   => rtrim($baseUrl, '/'),
            'verify_tls' => (bool) $device->getAttrib('api_verify_ssl', true),
            'timeout_ms' => (int) $device->getAttrib('api_timeout_ms', 10000),
            'proxy'      => $device->getAttrib('api_proxy'),
            'headers'    => $headers,
        ];
    }

    public static function rateLimitQps(Device $device): int
    {
        return (int) $device->getAttrib('api_rate_limit_qps', 10);
    }

    public static function recordSuccess(Device $device, int $latencyMs): void
    {
        $device->setAttrib('api_last_success', time());
        $device->setAttrib('api_error_count', 0);

        $currentAvg = (int) $device->getAttrib('api_avg_latency_ms', 0);
        $newAvg = $currentAvg === 0 ? $latencyMs : (int) (($currentAvg * 0.8) + ($latencyMs * 0.2));
        $device->setAttrib('api_avg_latency_ms', $newAvg);
    }

    public static function recordError(Device $device, string $error): void
    {
        $device->setAttrib('api_last_error', time());
        $device->setAttrib('api_last_error_message', substr($error, 0, 255));

        $errorCount = (int) $device->getAttrib('api_error_count', 0);
        $device->setAttrib('api_error_count', $errorCount + 1);
    }

    public static function getHealthStatus(Device $device): array
    {
        $lastSuccess = (int) $device->getAttrib('api_last_success', 0);
        $lastError = (int) $device->getAttrib('api_last_error', 0);
        $errorCount = (int) $device->getAttrib('api_error_count', 0);
        $avgLatency = (int) $device->getAttrib('api_avg_latency_ms', 0);

        $healthy = $errorCount === 0 || ($lastSuccess > 0 && $lastSuccess >= $lastError);

        return [
            'healthy' => $healthy,
            'last_success' => $lastSuccess,
            'last_error' => $lastError,
            'last_error_message' => $device->getAttrib('api_last_error_message'),
            'error_count' => $errorCount,
            'avg_latency_ms' => $avgLatency,
        ];
    }

    public static function shouldTripCircuitBreaker(Device $device, int $threshold = 5): bool
    {
        $errorCount = (int) $device->getAttrib('api_error_count', 0);
        return $errorCount >= $threshold;
    }

    public static function resetCircuitBreaker(Device $device): void
    {
        $device->setAttrib('api_error_count', 0);
        $device->setAttrib('api_last_error', 0);
        $device->setAttrib('api_last_error_message', '');
    }
}
