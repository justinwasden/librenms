<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\EndpointPathResolver;
use LibreNMS\Util\TransformRunner;

/**
 * Executes Device API templates against a device.
 * Features:
 * - Groups endpoints by method+path: single fetch, fan-out to multiple capabilities
 * - Caches inventory endpoints (TTL 15m)
 * - Proxmox {node} fallback: discovers node and persists proxmox_node on 404
 */
class DeviceApiExecutor
{
    /**
     * Execute all endpoints for a template against a device.
     *
     * @param Device $device
     * @param string $templateKey
     * @param object $client Must implement get(path, query=[]) and post(path, body=[]) -> array
     */
    public function run(Device $device, string $templateKey, $client): void
    {
        $tpl = ApiTemplateManager::loadTemplate($templateKey);
        if (!$tpl) {
            throw new \RuntimeException("Template $templateKey not found or disabled");
        }

        // Group endpoints by method+path to fetch once per unique path.
        $byPath = [];
        foreach ($tpl['endpoints'] as $ep) {
            if (!$ep['enabled']) {
                continue;
            }
            $method = strtoupper($ep['method'] ?? 'GET');
            $key = $method . ' ' . $ep['path'];
            $byPath[$key][] = $ep;
        }

        foreach ($byPath as $key => $eps) {
            [$method, $rawPath] = explode(' ', $key, 2);
            $resolvedPath = EndpointPathResolver::resolve($device, $rawPath);

            // Inventory endpoints get cached
            $capabilities = array_unique(array_map(fn($e) => $e['capability'], $eps));
            $cacheable = in_array('inventory', $capabilities, true);

            // Fetch closure (single request executed once per unique path)
            $fetch = function () use ($client, $method, $resolvedPath, $eps) {
                if ($method === 'POST') {
                    $body = $eps[0]['request_body'] ?? [];
                    return $client->post($resolvedPath, $body);
                }
                return $client->get($resolvedPath);
            };

            // Attempt fetch with Proxmox {node} fallback on 404/failed
            $payload = null;
            $attemptedFallback = false;

            try {
                $payload = $cacheable
                    ? Cache::remember($this->cacheKey($device, $method, $resolvedPath), now()->addMinutes(15), $fetch)
                    : $fetch();
            } catch (\Throwable $e) {
                // If the original path used {node}, try to discover proxmox node and retry once
                if (str_contains($rawPath, '{node}') && !$attemptedFallback) {
                    $attemptedFallback = true;

                    try {
                        // Discover nodes and pick best match
                        $nodesPayload = $client->get('/nodes'); // Proxmox API: returns ['data' => [ ['node'=>'...'], ...]]
                        $candidate = $this->pickProxmoxNode($device, $nodesPayload);
                        if ($candidate) {
                            $device->setAttrib('proxmox_node', $candidate);
                            // Re-resolve path with persisted node and retry fetch
                            $resolvedPath = EndpointPathResolver::resolve($device, $rawPath);
                            $payload = $cacheable
                                ? Cache::remember($this->cacheKey($device, $method, $resolvedPath), now()->addMinutes(15), $fetch)
                                : $fetch();
                        } else {
                            throw $e;
                        }
                    } catch (\Throwable $inner) {
                        throw $e; // original exception if fallback fails
                    }
                } else {
                    throw $e;
                }
            }

            // Fan-out transforms per endpoint/capability using the same payload
            foreach ($eps as $ep) {
                $result = TransformRunner::run($ep['transform'], $payload, $ep['capability']);
                $this->persist($device, $ep['capability'], $result);
            }
        }
    }

    protected function cacheKey(Device $device, string $method, string $resolvedPath): string
    {
        return "api:{$device->device_id}:{$method}:{$resolvedPath}";
    }

    /**
     * Choose a Proxmox node from /nodes payload, prefer match to sysname/hostname, else first node.
     */
    protected function pickProxmoxNode(Device $device, array $nodesPayload): ?string
    {
        $sysname = (string) ($device->sysName ?? $device->sysname ?? $device->hostname ?? '');
        $list = $nodesPayload['data'] ?? $nodesPayload['nodes'] ?? $nodesPayload ?? [];

        $first = null;
        foreach ($list as $node) {
            $nodeName = $node['node'] ?? $node['name'] ?? null;
            if (!$nodeName) {
                continue;
            }
            if ($first === null) {
                $first = $nodeName;
            }
            if ($nodeName === $sysname) {
                return $nodeName;
            }
        }
        return $first;
    }

    /**
     * Persist results to LibreNMS tables according to capability.
     * Replace placeholders with your actual persistence functions.
     */
    protected function persist(Device $device, string $capability, array $data): void
		{
		    if (empty($data)) {
		        return;
		    }

		    switch ($capability) {
		        case 'ports':
		            \App\Services\DeviceApiPersistor::savePorts($device, $data);
		            break;
		        case 'sensors':
		            \App\Services\DeviceApiPersistor::saveSensors($device, $data);
		            break;
		        case 'processors':
		            \App\Services\DeviceApiPersistor::saveProcessors($device, $data);
		            break;
		        case 'mempools':
		            \App\Services\DeviceApiPersistor::saveMempools($device, $data);
		            break;
		        case 'inventory':
		            \App\Services\DeviceApiPersistor::saveInventory($device, $data);
		            break;
		        default:
		            // Unknown capability
		            break;
		    }
		}
}