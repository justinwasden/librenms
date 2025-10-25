<?php
namespace LibreNMS\Util;

use App\Models\Device;
use Illuminate\Support\Facades\Crypt;

class DeviceApiSettings
{
    public static function restEnabled(Device $device): bool
    {
        return (bool) ($device->attribs['rest_enabled'] ?? 0);
    }

    public static function vendor(Device $device): ?string
    {
        return $device->attribs['rest_vendor'] ?? null; // e.g., 'purestorage', 'proxmox'
    }

    public static function httpOptions(Device $device): array
    {
        $a = $device->attribs ?? [];
        return [
            'base_url'   => rtrim($a['rest_base_url'] ?? $a['proxmox_base_url'] ?? '', '/'),
            'verify_tls' => (bool)($a['rest_verify_tls'] ?? $a['proxmox_verify_tls'] ?? true),
            'timeout_ms' => (int)($a['rest_timeout_ms'] ?? $a['proxmox_timeout_ms'] ?? 5000),
            'proxy'      => $a['rest_proxy'] ?? $a['proxmox_proxy'] ?? null,
            'headers'    => !empty($a['rest_headers']) ? (json_decode($a['rest_headers'], true) ?: []) : [],
        };
    }

    // Pure Storage specific
    public static function pureOptions(Device $device): array
    {
        $a = $device->attribs ?? [];
        $token = !empty($a['rest_token_enc']) ? Crypt::decryptString($a['rest_token_enc']) : ($a['rest_token'] ?? '');
        return [
            'auth_type' => $a['rest_auth_type'] ?? 'apikey',
            'token'     => $token,
        ];
    }

    // Proxmox specific
    public static function proxmoxOptions(Device $device): array
    {
        $a = $device->attribs ?? [];
        $mode = $a['proxmox_auth_type'] ?? 'token';
        $opts = ['auth_type' => $mode];

        if ($mode === 'token') {
            $secret = !empty($a['proxmox_token_enc']) ? Crypt::decryptString($a['proxmox_token_enc']) : '';
            $opts += [
                'token_user' => $a['proxmox_token_user'] ?? '',
                'token_id'   => $a['proxmox_token_id'] ?? '',
                'token'      => $secret,
            ];
        } else {
            $password = !empty($a['proxmox_password_enc']) ? Crypt::decryptString($a['proxmox_password_enc']) : '';
            $opts += [
                'username' => $a['proxmox_username'] ?? '',
                'password' => $password,
            ];
        }

        return $opts;
    }
}