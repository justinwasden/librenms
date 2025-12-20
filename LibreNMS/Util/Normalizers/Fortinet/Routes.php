<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - Routes Normalizer
 *
 * Capability: routes
 * Vendor: fortigate
 */
class Routes extends BaseNormalizer
{
    protected string $capability = 'routes';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
$routes = [];
        $results = $payload['results'] ?? [];

        if (!is_array($results)) {
            return $routes;
        }

        foreach ($results as $route) {
            $network = $route['ip_address'] ?? '';
            $mask = $route['ip_mask'] ?? '';
            $nexthop = $route['gateway'] ?? '';
            $interface = $route['interface'] ?? '';
            $distance = $route['distance'] ?? 0;
            $metric = $route['metric'] ?? 0;
            $type = $route['type'] ?? 'static';

            if (!$network || !$mask) {
                continue;
            }

            $routes[] = [
                'context_name' => '',
                'inetCidrRouteDestType' => 'ipv4',
                'inetCidrRouteDest' => $network,
                'inetCidrRoutePfxLen' => $this->netmaskToCidr($mask),
                'inetCidrRoutePolicy' => '',
                'inetCidrRouteNextHopType' => 'ipv4',
                'inetCidrRouteNextHop' => $nexthop ?: '0.0.0.0',
                'inetCidrRouteIfIndex' => $this->stableIndexFromName($interface),
                'inetCidrRouteType' => $type === 'connected' ? 'local' : 'remote',
                'inetCidrRouteProto' => $type,
                'inetCidrRouteAge' => 0,
                'inetCidrRouteNextHopAS' => 0,
                'inetCidrRouteMetric1' => $distance,
                'inetCidrRouteMetric2' => $metric,
            ];
        }

        return $routes;
    }
}
