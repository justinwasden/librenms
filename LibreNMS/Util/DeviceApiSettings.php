<?php
namespace LibreNMS\Util;

use App\Models\Device;
use App\Models\DeviceApiConfig;

class DeviceApiSettings
{
    /**
     * Get the API config for a device
     */
    protected static function getConfig(Device $device): ?DeviceApiConfig
    {
        return $device->apiConfig ?? DeviceApiConfig::where('device_id', $device->device_id)->first();
    }

    /**
     * Resolve and persist base_url from the selected template's base_url_pattern.
     */
    public static function ensureResolvedBaseUrl(Device $device): void
    {
        $apiConfig = self::getConfig($device);
        if (!$apiConfig || !$apiConfig->template) {
            return;
        }

        $tpl = ApiTemplateManager::loadTemplate($apiConfig->template->key);
        if (!$tpl || empty($tpl['base_url_pattern'])) {
            return;
        }

        $resolved = EndpointPathResolver::resolveBaseUrl($device, $tpl['base_url_pattern']);
        $current = $apiConfig->base_url;

        if (!$current || $current !== $resolved) {
            $apiConfig->base_url = $resolved;
            $apiConfig->save();
        }
    }

    public static function restEnabled(Device $device): bool
    {
        $apiConfig = self::getConfig($device);
        return $apiConfig !== null;
    }

    public static function vendor(Device $device): ?string
    {
        $apiConfig = self::getConfig($device);
        return $apiConfig?->template?->key;
    }

    public static function httpOptions(Device $device): array
    {
        self::ensureResolvedBaseUrl($device);

        $apiConfig = self::getConfig($device);
        if (!$apiConfig) {
            // Return defaults if no config exists
            return [
                'base_url'   => '',
                'verify_tls' => true,
                'timeout_ms' => 5000,
                'proxy'      => null,
                'headers'    => [],
            ];
        }

        $headers = $apiConfig->extra_headers ?? [];

        return [
            'base_url'   => rtrim($apiConfig->base_url ?? '', '/'),
            'verify_tls' => (bool) $apiConfig->verify_ssl,
            'timeout_ms' => (int) ($apiConfig->getValue('timeout_ms') ?? 5000),
            'proxy'      => $apiConfig->getValue('proxy'),
            'headers'    => $headers,
        ];
    }

    public static function rateLimitQps(Device $device): int
    {
        $apiConfig = self::getConfig($device);
        return (int) ($apiConfig?->getValue('rate_limit_qps') ?? 10);
    }

    public static function recordSuccess(Device $device, int $latencyMs): void
    {
        $device->setAttrib('rest_last_success', time());
        $device->setAttrib('rest_error_count', 0);

        $currentAvg = (int) ($device->attribs['rest_avg_latency_ms'] ?? 0);
        $newAvg = $currentAvg === 0 ? $latencyMs : (int) (($currentAvg * 0.8) + ($latencyMs * 0.2));
        $device->setAttrib('rest_avg_latency_ms', $newAvg);
    }

    public static function recordError(Device $device, string $error): void
    {
        $device->setAttrib('rest_last_error', time());
        $device->setAttrib('rest_last_error_message', substr($error, 0, 255));

        $errorCount = (int) ($device->attribs['rest_error_count'] ?? 0);
        $device->setAttrib('rest_error_count', $errorCount + 1);
    }

    public static function getHealthStatus(Device $device): array
    {
        $lastSuccess = (int) ($device->attribs['rest_last_success'] ?? 0);
        $lastError = (int) ($device->attribs['rest_last_error'] ?? 0);
        $errorCount = (int) ($device->attribs['rest_error_count'] ?? 0);
        $avgLatency = (int) ($device->attribs['rest_avg_latency_ms'] ?? 0);

        $healthy = $errorCount === 0 || ($lastSuccess > 0 && $lastSuccess >= $lastError);

        return [
            'healthy' => $healthy,
            'last_success' => $lastSuccess,
            'last_error' => $lastError,
            'last_error_message' => $device->attribs['rest_last_error_message'] ?? null,
            'error_count' => $errorCount,
            'avg_latency_ms' => $avgLatency,
        ];
    }

    public static function shouldTripCircuitBreaker(Device $device, int $threshold = 5): bool
    {
        $errorCount = (int) ($device->attribs['rest_error_count'] ?? 0);
        return $errorCount >= $threshold;
    }

    public static function resetCircuitBreaker(Device $device): void
    {
        $device->setAttrib('rest_error_count', 0);
        $device->setAttrib('rest_last_error', 0);
        $device->setAttrib('rest_last_error_message', '');
    }
}
