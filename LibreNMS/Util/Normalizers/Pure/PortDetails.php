<?php

namespace LibreNMS\Util\Normalizers\Pure;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Pure - PortDetails Normalizer
 *
 * Capability: unknown
 * Vendor: pure
 */
class PortDetails extends BaseNormalizer
{
    protected string $capability = 'unknown';
    protected string $vendor = 'pure';

    protected function doNormalize(Device $device, array $payload): array
    {
$transceivers = [];

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            return $transceivers;
        }

        foreach ($payload['items'] as $port) {
            $name = $port['name'] ?? 'unknown';
            $ifIndex = $this->stableIndexFromName($name);

            // Pure Storage transceiver data is under items.static
            $static = $port['static'] ?? [];

            if (!empty($static)) {
                // Parse distance from string like "Copper Cable: 1 m" or "Single-mode Fiber: 10 km" to integer meters
                $distance = null;
                if (isset($static['link_length'])) {
                    $linkLength = $static['link_length'];
                    if (preg_match('/(\d+(?:\.\d+)?)\s*(m|km)/i', $linkLength, $matches)) {
                        $value = (float) $matches[1];
                        $unit = strtolower($matches[2]);
                        $distance = $unit === 'km' ? (int) ($value * 1000) : (int) $value;
                    }
                }

                $trans = [
                    'ifName' => $name,
                    'index' => $ifIndex,
                    'type' => $static['identifier'] ?? null,
                    'vendor' => $static['vendor_name'] ?? null,
                    'oui' => $static['vendor_oui'] ?? null,
                    'model' => $static['vendor_part_number'] ?? null,
                    'revision' => $static['vendor_revision'] ?? null,
                    'serial' => $static['vendor_serial_number'] ?? null,
                    'date' => $static['vendor_date_code'] ?? null,
                    'connector' => $static['connector_type'] ?? null,
                    'distance' => $distance,
                    'wavelength' => $static['wavelength'] ?? null,
                    'cable' => isset($static['cable_technology']) && is_array($static['cable_technology'])
                        ? implode(', ', $static['cable_technology'])
                        : ($static['cable_technology'] ?? null),
                    'encoding' => $static['encoding'] ?? null,
                    'channels' => $static['channels'] ?? 1,
                ];
                $transceivers[] = $trans;
            }
        }

        return $transceivers;
    }
}
