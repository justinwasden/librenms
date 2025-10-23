<?php
namespace App\Services\RestApi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use App\Services\RestApi\Auth\AuthManager;

trait ProxmoxPlaceholderResolver
{
    /**
     * Resolve {node} and {storage} placeholders for Proxmox endpoints.
     * Caches resolved values per device to reduce API lookups.
     */
    protected function resolveProxmoxPath(RestApiConnection $connection, RestApiEndpoint $endpoint): string
    {
        $path = $endpoint->path;
        $needsNode = str_contains($path, '{node}');
        $needsStorage = str_contains($path, '{storage}');

        if (!$needsNode && !$needsStorage) {
            return $path;
        }

        // Authenticated client (use endpoint http method or default GET for lookups)
        $authManager = new AuthManager();
        $request = $authManager->getRequest($connection, $connection->credential, 'GET');

        // Resolve node
        $nodeName = null;
        if ($needsNode) {
            $nodeCacheKey = 'proxmox:node:' . $connection->device_id;
            $nodeName = Cache::get($nodeCacheKey);
            if (!$nodeName) {
                // Try to use device hostname as node name if it matches Proxmox node-naming
                $nodeName = $connection->device->hostname ?? null;

                // Fallback: query /api2/json/nodes
                try {
                    $url = rtrim($connection->base_url, '/') . '/api2/json/nodes';
                    $resp = $request->get($url);
                    if ($resp->successful()) {
                        $json = $resp->json();
                        $items = $json['data'] ?? $json['nodes'] ?? $json['items'] ?? [];
                        if (is_array($items) && !empty($items)) {
                            // Heuristic: if device hostname matches a node, use it; else use the first node
                            $names = array_map(fn($i) => $i['node'] ?? $i['name'] ?? null, $items);
                            $names = array_filter($names);
                            if ($names) {
                                if ($nodeName && in_array($nodeName, $names, true)) {
                                    // Use matching
                                } else {
                                    $nodeName = reset($names);
                                }
                            }
                        }
                    } else {
                        Log::warning('Proxmox nodes lookup failed', ['status' => $resp->status(), 'body' => $resp->body()]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Proxmox nodes lookup exception', ['error' => $e->getMessage()]);
                }

                if ($nodeName) {
                    Cache::put($nodeCacheKey, $nodeName, 600); // 10 minutes
                }
            }

            if ($nodeName) {
                $path = str_replace('{node}', $nodeName, $path);
            } else {
                Log::warning('Could not resolve {node} placeholder', ['device_id' => $connection->device_id, 'endpoint' => $endpoint->path]);
            }
        }

        // Resolve storage
        if ($needsStorage) {
            $storageCacheKey = 'proxmox:storage:' . $connection->device_id;
            $storageName = Cache::get($storageCacheKey);
            if (!$storageName) {
                // Try device attrib override
                $storageName = $connection->device->getAttrib('proxmox_storage') ?? null;

                // Fallback: query /api2/json/nodes/<node>/storage
                if ($nodeName) {
                    try {
                        $url = rtrim($connection->base_url, '/') . '/api2/json/nodes/' . urlencode($nodeName) . '/storage';
                        $resp = $request->get($url);
                        if ($resp->successful()) {
                            $json = $resp->json();
                            $items = $json['data'] ?? $json['items'] ?? [];
                            if (is_array($items) && !empty($items)) {
                                // pick first storage or one matching attribute
                                $names = array_map(fn($i) => $i['storage'] ?? $i['name'] ?? null, $items);
                                $names = array_filter($names);
                                if ($names) {
                                    if ($storageName && in_array($storageName, $names, true)) {
                                        // keep configured storage
                                    } else {
                                        $storageName = reset($names);
                                    }
                                }
                            }
                        } else {
                            Log::warning('Proxmox storage lookup failed', ['status' => $resp->status(), 'body' => $resp->body()]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Proxmox storage lookup exception', ['error' => $e->getMessage()]);
                    }
                }

                if ($storageName) {
                    Cache::put($storageCacheKey, $storageName, 600); // 10 minutes
                }
            }

            if ($storageName) {
                $path = str_replace('{storage}', $storageName, $path);
            } else {
                Log::warning('Could not resolve {storage} placeholder', ['device_id' => $connection->device_id, 'endpoint' => $endpoint->path]);
            }
        }

        return $path;
    }
}