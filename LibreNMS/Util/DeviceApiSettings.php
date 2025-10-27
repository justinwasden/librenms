<?php
namespace LibreNMS\Util;

use App\Models\Device;
use Illuminate\Support\Facades\Crypt;

class DeviceApiSettings
{
    protected static function read(Device $device, string $key, $default = null)
    {
        if (method_exists($device, 'getAttrib')) {
            $val = $device->getAttrib($key, $default);
            return $val !== null ? $val : $default;
        }
        $a = $device->attribs ?? [];
        return array_key_exists($key, $a) ? $a[$key] : $default;
    }

    /**
     * Resolve and persist rest_base_url from the selected template's base_url_pattern.
     * Requires device attrib 'rest_template_key' to be set when selecting a template.
     */
    public static function ensureResolvedBaseUrl(Device $device): void
    {
        $tplKey = self::read($device, 'rest_template_key', null);
        if (!$tplKey) {
            return;
        }

        $tpl = \LibreNMS\Util\ApiTemplateManager::loadTemplate($tplKey);
        if (!$tpl || empty($tpl['base_url_pattern'])) {
            return;
        }

        $resolved = \LibreNMS\Util\EndpointPathResolver::resolveBaseUrl($device, $tpl['base_url_pattern']);
        $current = self::read($device, 'rest_base_url', null);

        if (!$current || $current !== $resolved) {
            $device->setAttrib('rest_base_url', $resolved);
        }
    }

    public static function restEnabled(Device $device): bool
    {
        return (bool) self::read($device, 'rest_enabled', 0);
    }

    public static function vendor(Device $device): ?string
    {
        $v = self::read($device, 'rest_vendor', null);
        return $v !== '' ? $v : null;
    }

    public static function httpOptions(Device $device): array
		{
		    self::ensureResolvedBaseUrl($device);

		    $a = $device->attribs ?? [];
		    $headers = [];
		    if (!empty($a['rest_headers'])) {
		        $decoded = json_decode($a['rest_headers'], true);
		        if (is_array($decoded)) {
		            $headers = $decoded;
		        }
		    }

		    return [
		        'base_url'   => rtrim(($a['rest_base_url'] ?? ($a['proxmox_base_url'] ?? '')), '/'),
		        'verify_tls' => (bool) (($a['rest_verify_tls'] ?? ($a['proxmox_verify_tls'] ?? true))),
		        'timeout_ms' => (int) ($a['rest_timeout_ms'] ?? ($a['proxmox_timeout_ms'] ?? 5000)),
		        'proxy'      => $a['rest_proxy'] ?? ($a['proxmox_proxy'] ?? null),
		        'headers'    => $headers,
		    ];
		}

    // Pure options (unchanged)
    public static function pureOptions(Device $device): array
    {
        $a = $device->attribs ?? array();
        $token = '';
        if (!empty($a['rest_token_enc'])) {
            $token = Crypt::decryptString($a['rest_token_enc']);
        } elseif (!empty($a['rest_token'])) {
            $token = $a['rest_token'];
        }

        return array(
            'auth_type' => $a['rest_auth_type'] ?? 'apikey',
            'token'     => $token,
        );
    }

    // Proxmox options (unchanged)
    public static function proxmoxOptions(Device $device): array
    {
        $a = $device->attribs ?? array();
        $mode = $a['proxmox_auth_type'] ?? 'token';

        $opts = array('auth_type' => $mode);

        if ($mode === 'token') {
            $secret = '';
            if (!empty($a['proxmox_token_enc'])) {
                $secret = Crypt::decryptString($a['proxmox_token_enc']);
            }
            $opts = array_merge($opts, array(
                'token_user' => $a['proxmox_token_user'] ?? '',
                'token_id'   => $a['proxmox_token_id'] ?? '',
                'token'      => $secret,
            ));
        } else {
            $password = '';
            if (!empty($a['proxmox_password_enc'])) {
                $password = Crypt::decryptString($a['proxmox_password_enc']);
            }
            $opts = array_merge($opts, array(
                'username' => $a['proxmox_username'] ?? '',
                'password' => $password,
            ));
        }

        return $opts;
    }

    public static function rateLimitQps(Device $device): int
    {
        return (int) ($device->attribs['rest_rate_limit_qps'] ?? 10);
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