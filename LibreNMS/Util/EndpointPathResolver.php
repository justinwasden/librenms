<?php

namespace LibreNMS\Util;

use App\Models\Device;

class EndpointPathResolver
{
    /**
     * Replace known placeholders in endpoint paths.
     * Supported: {hostname}, {sysname}, {node}
     * - {node} prefers device attrib 'proxmox_node', falls back to sysname if it doesn't look like an IP.
     */
    public static function resolve(Device $device, string $path): string
    {
        $hostname = (string) ($device->hostname ?? '');
        $sysname  = (string) ($device->sysName ?? $device->sysname ?? $hostname);

        // For {node} placeholder, prefer proxmox_node attribute
        // If not set, fallback to sysname only if it doesn't look like an IP address
        $node = (string) $device->getAttrib('proxmox_node');
        if (!$node) {
            // Only use sysname as fallback if it's not an IP address
            if ($sysname && !filter_var($sysname, FILTER_VALIDATE_IP)) {
                $node = $sysname;
            } else {
                // If sysname is an IP, try to extract hostname without domain
                if ($hostname && !filter_var($hostname, FILTER_VALIDATE_IP)) {
                    $node = explode('.', $hostname)[0];
                } else {
                    // Last resort: use sysname as-is, but log a warning
                    $node = $sysname;
                    if (str_contains($path, '{node}')) {
                        \Log::warning("EndpointPathResolver: Using IP address as node placeholder for device {$device->device_id}. Consider setting 'proxmox_node' attribute.");
                    }
                }
            }
        }

        $map = [
            '{hostname}' => $hostname,
            '{sysname}'  => $sysname,
            '{node}'     => $node,
        ];

        return strtr($path, $map);
    }

    /**
     * Resolve base_url_pattern placeholders too (e.g., https://{hostname}:8006/api2/json).
     */
    public static function resolveBaseUrl(Device $device, string $pattern): string
    {
        $hostname = (string) ($device->hostname ?? '');
        $sysname  = (string) ($device->sysName ?? $device->sysname ?? $hostname);

        // Use same logic as resolve() for {node} placeholder
        $node = (string) $device->getAttrib('proxmox_node');
        if (!$node) {
            if ($sysname && !filter_var($sysname, FILTER_VALIDATE_IP)) {
                $node = $sysname;
            } else {
                if ($hostname && !filter_var($hostname, FILTER_VALIDATE_IP)) {
                    $node = explode('.', $hostname)[0];
                } else {
                    $node = $sysname;
                }
            }
        }

        $map = [
            '{hostname}' => $hostname,
            '{sysname}'  => $sysname,
            '{node}'     => $node,
        ];

        return rtrim(strtr($pattern, $map), '/');
    }
}