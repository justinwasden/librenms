<?php

namespace LibreNMS\Util;

use App\Models\Device;

class EndpointPathResolver
{
    /**
     * Replace known placeholders in endpoint paths.
     * Supported: {hostname}, {sysname}, {node}
     * - {node} prefers device attrib 'proxmox_node', falls back to sysname.
     */
    public static function resolve(Device $device, string $path): string
    {
        $hostname = (string) ($device->hostname ?? '');
        $sysname  = (string) ($device->sysName ?? $device->sysname ?? $hostname);
        $node     = (string) ($device->getAttrib('proxmox_node') ?? $sysname);

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
        $node     = (string) ($device->getAttrib('proxmox_node') ?? $sysname);

        $map = [
            '{hostname}' => $hostname,
            '{sysname}'  => $sysname,
            '{node}'     => $node,
        ];

        return rtrim(strtr($pattern, $map), '/');
    }
}