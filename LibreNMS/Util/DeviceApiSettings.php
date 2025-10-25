<?php
namespace LibreNMS\Util;

use App\Models\Device;
use Illuminate\Support\Facades\Crypt;

class DeviceApiSettings
{
    public static function restEnabled(Device $device): bool
    {
        return (bool) (($device->attribs['rest_enabled'] ?? 0));
    }

    public static function vendor(Device $device): ?string
    {
        return $device->attribs['rest_vendor'] ?? null; // e.g., 'purestorage', 'proxmox'
    }

    public static function httpOptions(Device $device): array
    {
        $a = $device->attribs ?? [];

        $headers = array();
        if (!empty($a['rest_headers'])) {
            $decoded = json_decode($a['rest_headers'], true);
            if (is_array($decoded)) {
                $headers = $decoded;
            }
        }

        return array(
            'base_url'   => rtrim(($a['rest_base_url'] ?? ($a['proxmox_base_url'] ?? '')), '/'),
            'verify_tls' => (bool)(($a['rest_verify_tls'] ?? ($a['proxmox_verify_tls'] ?? true))),
            'timeout_ms' => (int)(($a['rest_timeout_ms'] ?? ($a['proxmox_timeout_ms'] ?? 5000))),
            'proxy'      => $a['rest_proxy'] ?? ($a['proxmox_proxy'] ?? null),
            'headers'    => $headers,
        );
    }

    // Pure Storage specific
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

    // Proxmox specific
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
}