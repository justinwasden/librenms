<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - Controller Hardware Normalizer
 *
 * Extracts controller serial numbers and models from the /hardware?filter=type='controller' endpoint.
 * This data is merged with the base controller data from /controllers endpoint.
 *
 * Capability: controllers
 * Vendor: pure
 */
class ControllerHardware extends BaseNormalizer
{
    protected string $capability = 'controllers';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
        $controllers = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return ['controllers' => $controllers];
        }

        foreach ($payload['items'] as $hw) {
            $name = $hw['name'] ?? '';
            $type = strtolower($hw['type'] ?? '');

            // Only process controller type hardware
            if ($type !== 'controller') {
                continue;
            }

            $controllers[] = [
                'controller_name' => $name,
                'name' => $name,
                'serial' => $hw['serial'] ?? '',
                'model' => $hw['model'] ?? '',
                'status' => $hw['status'] ?? 'unknown',
                'mode' => '', // Not available from hardware endpoint
                'version' => $hw['version'] ?? '',
            ];
        }

        return ['controllers' => $controllers];
    }
}
