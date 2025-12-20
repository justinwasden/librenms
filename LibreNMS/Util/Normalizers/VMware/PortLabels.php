<?php

namespace LibreNMS\Util\Normalizers\VMware;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * VMware - PortLabels Normalizer
 *
 * Capability: unknown
 * Vendor: velocloud
 */
class PortLabels extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'velocloud';

    protected function doNormalize(Device $device, array $payload): array
    {
$data = $payload['data'] ?? $payload;
        $ports = [];

        if (!is_array($data)) {
            return [];
        }

        // Track unique interfaces to avoid duplicates
        $seen = [];

        foreach ($data as $link) {
            // Extract link information
            $linkInfo = $link['link'] ?? [];
            $interfaceName = $linkInfo['interface'] ?? $link['name'] ?? null;

            if (!$interfaceName || isset($seen[$interfaceName])) {
                continue;
            }
            $seen[$interfaceName] = true;

            // Get displayName (ISP/carrier label)
            $displayName = $linkInfo['displayName'] ?? $link['displayName'] ?? null;

            if ($displayName) {
                $ports[] = [
                    'ifName' => $interfaceName,
                    'ifAlias' => $displayName,
                ];
            }
        }

        return $ports;
    }
}
