<?php

namespace App\Services;

use App\Models\Device;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\DeviceApiSettings;
use LibreNMS\Util\EndpointPathResolver;
use LibreNMS\Util\TransformRunner;

/**
 * Per-device REST API gateway for modules.
 * - Groups endpoints by method+path
 * - Caches responses per device+path in-memory for this PHP process
 * - Resolves {node}/{hostname}/{sysname} placeholders
 * - Returns data normalized by capability
 */
class DeviceApiGateway
{
    /** @var array<string, array> */
    protected static array $payloadCache = []; // key: device_id|METHOD|resolvedPath => payload

    /** @var array<string, array> */
    protected static array $capabilityCache = []; // key: device_id|capability => normalized data

    public function getCapabilityData(Device $device, string $capability): array
    {
        if ((int)$device->getAttrib('rest_enabled') !== 1) {
            return [];
        }
        $tplKey = (string)$device->getAttrib('rest_template_key');
        if ($tplKey === '') {
            return [];
        }

        $cacheKey = $device->device_id . '|' . $capability;
        if (isset(self::$capabilityCache[$cacheKey])) {
            return self::$capabilityCache[$cacheKey];
        }

        DeviceApiSettings::ensureResolvedBaseUrl($device);

        $tpl = ApiTemplateManager::loadTemplate($tplKey);
        if (!$tpl) {
            return [];
        }

        // Collect endpoints for this capability
        $epsForCap = array_values(array_filter($tpl['endpoints'] ?? [], function ($ep) use ($capability) {
            return ($ep['enabled'] ?? true) && ($ep['capability'] === $capability);
        }));

        if (empty($epsForCap)) {
            self::$capabilityCache[$cacheKey] = [];
            return [];
        }

        // Build client once
        $client = $this->makeClient($device, $tpl);

        // Execute endpoints, grouping by method+path to reuse payload when multiple endpoints share the same path
        $byPath = [];
        foreach ($epsForCap as $ep) {
            $key = strtoupper($ep['method'] ?? 'GET') . ' ' . $ep['path'];
            $byPath[$key][] = $ep;
        }

        $result = [];
        foreach ($byPath as $key => $eps) {
            [$method, $rawPath] = explode(' ', $key, 2);
            $resolvedPath = EndpointPathResolver::resolve($device, $rawPath);

            $payloadCacheKey = $device->device_id . '|' . $method . '|' . $resolvedPath;
            if (!isset(self::$payloadCache[$payloadCacheKey])) {
                // Fetch (with Proxmox {node} fallback on failure)
                try {
                    self::$payloadCache[$payloadCacheKey] = $this->fetch($client, $method, $resolvedPath, $eps);
                } catch (\Throwable $e) {
                    // Proxmox {node} fallback: discover nodes and retry once
                    if (str_contains($rawPath, '{node}')) {
                        $nodes = $client->get('/nodes');
                        $candidate = $this->pickProxmoxNode($device, $nodes);
                        if ($candidate) {
                            $device->setAttrib('proxmox_node', $candidate);
                            $resolvedPath = EndpointPathResolver::resolve($device, $rawPath);
                            $payloadCacheKey = $device->device_id . '|' . $method . '|' . $resolvedPath;
                            self::$payloadCache[$payloadCacheKey] = $this->fetch($client, $method, $resolvedPath, $eps);
                        } else {
                            self::$payloadCache[$payloadCacheKey] = [];
                        }
                    } else {
                        self::$payloadCache[$payloadCacheKey] = [];
                    }
                }
            }

            $payload = self::$payloadCache[$payloadCacheKey] ?? [];
            foreach ($eps as $ep) {
                $data = TransformRunner::run($ep['transform'] ?? null, $payload, $capability);
                if (!empty($data)) {
                    // Merge results (some capability endpoints return flat lists)
                    $result = array_merge($result, $data);
                }
            }
        }

        self::$capabilityCache[$cacheKey] = $result;
        return $result;
    }

    protected function fetch($client, string $method, string $path, array $eps)
    {
        if (strtoupper($method) === 'POST') {
            $body = $eps[0]['request_body'] ?? [];
            return $client->post($path, $body);
        }
        return $client->get($path);
    }

    protected function makeClient(Device $device, array $tpl)
    {
        return match ($tpl['vendor']) {
            'proxmox_ve_token', 'proxmox_ve_ticket' => new \App\ApiClients\Proxmox\ProxmoxApiClient($device),
            'purestorage_flasharray' => new \App\ApiClients\PureStorage\FlashArrayClient($device, [
                'strategy_key' => $tpl['auth_type'],
            ]),
            'vmware_vcenter' => class_exists(\App\ApiClients\Vmware\VcenterClient::class)
                ? new \App\ApiClients\Vmware\VcenterClient($device)
                : new \App\ApiClients\Generic\RestClient($device),
            default => class_exists(\App\ApiClients\Generic\RestClient::class)
                ? new \App\ApiClients\Generic\RestClient($device)
                : new \App\ApiClients\PureStorage\FlashArrayClient($device), // fallback to an existing client
        };
    }

    protected function pickProxmoxNode(Device $device, array $nodesPayload): ?string
    {
        $sysname = (string) ($device->sysName ?? $device->sysname ?? $device->hostname ?? '');
        $list = $nodesPayload['data'] ?? $nodesPayload['nodes'] ?? $nodesPayload ?? [];
        $first = null;
        foreach ($list as $node) {
            $name = $node['node'] ?? $node['name'] ?? null;
            if (!$name) continue;
            if ($first === null) $first = $name;
            if ($name === $sysname) return $name;
        }
        return $first;
    }

    /**
     * Clear caches for a new poll cycle (call once at start of per-device poll).
     */
    public function resetCycle(Device $device): void
    {
        // Clear capability cache and payloads for this device
        foreach (array_keys(self::$capabilityCache) as $k) {
            if (str_starts_with($k, $device->device_id . '|')) {
                unset(self::$capabilityCache[$k]);
            }
        }
        foreach (array_keys(self::$payloadCache) as $k) {
            if (str_starts_with($k, $device->device_id . '|')) {
                unset(self::$payloadCache[$k]);
            }
        }
    }
}