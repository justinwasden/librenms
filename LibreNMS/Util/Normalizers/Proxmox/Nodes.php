<?php

namespace LibreNMS\Util\Normalizers\Proxmox;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Proxmox - Nodes Normalizer
 *
 * Capability: unknown
 * Vendor: proxmox
 */
class Nodes extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'proxmox';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $hosts = [];

        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $node) {
            $nodeName = $node['node'] ?? null;
            if (!$nodeName) {
                continue;
            }

            // Map Proxmox status to our status values
            $status = $node['status'] ?? 'unknown';
            $status = match(strtolower($status)) {
                'online' => 'connected',
                'offline' => 'disconnected',
                default => strtolower($status),
            };

            $hosts[] = [
                'host_type' => 'proxmox-node',
                'host_id' => $nodeName,
                'host_name' => $nodeName,
                'cluster_id' => null,
                'role' => 'node',
                'status' => $status,
                'version' => null, // Basic node list doesn't include version
                'cpu_cores' => $node['maxcpu'] ?? null,
                'cpu_threads' => null,
                'memory_total' => $node['maxmem'] ?? null,
                'ip_address' => $node['ip'] ?? null,
                'metadata' => [
                    'uptime' => $node['uptime'] ?? null,
                    'level' => $node['level'] ?? null,
                ],
            ];
        }

        return $hosts;
    }
}
