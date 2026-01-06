<?php
namespace LibreNMS\Util;

use App\ApiClients\TestableDevice;
use App\Models\Device;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class DeviceApiSettings
{
    /**
     * Get a credential value, decrypting if necessary
     *
     * Credentials stored in device attributes may be encrypted with Laravel's Crypt.
     * This method handles transparent decryption.
     *
     * @param Device|TestableDevice $device
     * @param string $key The credential key (e.g., 'api_credential_password')
     * @param mixed $default Default value if not found
     * @return string|null
     */
    public static function getCredential(Device|TestableDevice $device, string $key, $default = null): ?string
    {
        $value = $device->getAttrib($key, $default);

        if ($value === null || $value === '') {
            return $default;
        }

        // Check if the value appears to be Laravel-encrypted (base64 JSON starting with eyJ)
        if (is_string($value) && str_starts_with($value, 'eyJ')) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException $e) {
                // Not actually encrypted or wrong key, return as-is
                \Log::debug("DeviceApiSettings: Could not decrypt {$key}, using raw value", [
                    'device_id' => $device->device_id,
                ]);
                return $value;
            }
        }

        return $value;
    }

    public static function restEnabled(Device|TestableDevice $device): bool
    {
        return $device->getAttrib('api_enabled') || $device->getAttrib('api_base_url') !== null;
    }

    public static function vendor(Device|TestableDevice $device): ?string
    {
        // Return the OS as vendor identifier
        return $device->os;
    }

    public static function httpOptions(Device|TestableDevice $device): array
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

    public static function rateLimitQps(Device|TestableDevice $device): int
    {
        return (int) $device->getAttrib('api_rate_limit_qps', 10);
    }

    public static function recordSuccess(Device|TestableDevice $device, int $latencyMs): void
    {
        $device->setAttrib('rest_last_success', time());
        $device->setAttrib('rest_error_count', 0);

        $currentAvg = (int) $device->getAttrib('rest_avg_latency_ms', 0);
        $newAvg = $currentAvg === 0 ? $latencyMs : (int) (($currentAvg * 0.8) + ($latencyMs * 0.2));
        $device->setAttrib('rest_avg_latency_ms', $newAvg);
    }

    public static function recordError(Device|TestableDevice $device, string $error): void
    {
        $device->setAttrib('rest_last_error', time());
        $device->setAttrib('rest_last_error_message', substr($error, 0, 255));

        $errorCount = (int) $device->getAttrib('rest_error_count', 0);
        $device->setAttrib('rest_error_count', $errorCount + 1);
    }

    public static function getHealthStatus(Device|TestableDevice $device): array
    {
        $lastSuccess = (int) $device->getAttrib('rest_last_success', 0);
        $lastError = (int) $device->getAttrib('rest_last_error', 0);
        $errorCount = (int) $device->getAttrib('rest_error_count', 0);
        $avgLatency = (int) $device->getAttrib('rest_avg_latency_ms', 0);

        $healthy = $errorCount === 0 || ($lastSuccess > 0 && $lastSuccess >= $lastError);

        return [
            'healthy' => $healthy,
            'last_success' => $lastSuccess,
            'last_error' => $lastError,
            'last_error_message' => $device->getAttrib('rest_last_error_message'),
            'error_count' => $errorCount,
            'avg_latency_ms' => $avgLatency,
        ];
    }

    public static function shouldTripCircuitBreaker(Device|TestableDevice $device, int $threshold = 5): bool
    {
        $errorCount = (int) $device->getAttrib('rest_error_count', 0);
        return $errorCount >= $threshold;
    }

    public static function resetCircuitBreaker(Device|TestableDevice $device): void
    {
        $device->setAttrib('rest_error_count', 0);
        $device->setAttrib('rest_last_error', 0);
        $device->setAttrib('rest_last_error_message', '');
    }
}
