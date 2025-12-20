<?php

namespace LibreNMS\Util\Normalizers\Generic;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Generic - Transceivers Normalizer
 *
 * Capability: transceivers
 * Vendor: generic
 */
class Transceivers extends BaseNormalizer
{
    protected string $capability = 'transceivers';
    protected string $vendor = 'generic';

    protected function doNormalize(Device $device, array $payload): array
    {
$transceivers = [];
        $items = $payload['items'] ?? $payload['transceivers'] ?? $payload['optics'] ?? $payload;

        foreach ($items as $idx => $trans) {
            $ifIdentifier = null;
            if (isset($trans['ifIndex'])) {
                $ifIdentifier = ['ifIndex' => $trans['ifIndex']];
            } elseif (isset($trans['ifName'])) {
                $ifIdentifier = ['ifName' => $trans['ifName']];
            } elseif (isset($trans['interface']) || isset($trans['port'])) {
                $ifIdentifier = ['ifName' => $trans['interface'] ?? $trans['port']];
            }

            if (!$ifIdentifier) {
                continue;
            }

            $transceivers[] = array_merge($ifIdentifier, [
                'index'                 => $trans['index'] ?? $idx,
                'entity_physical_index' => $trans['entity_physical_index'] ?? null,
                'type'                  => $trans['type'] ?? $trans['form_factor'] ?? null,
                'vendor'                => $trans['vendor'] ?? $trans['manufacturer'] ?? null,
                'oui'                   => $trans['oui'] ?? null,
                'model'                 => $trans['model'] ?? $trans['part_number'] ?? null,
                'revision'              => $trans['revision'] ?? null,
                'serial'                => $trans['serial'] ?? $trans['serial_number'] ?? null,
                'date'                  => $trans['date'] ?? $trans['manufacture_date'] ?? null,
                'ddm'                   => isset($trans['ddm']) ? (bool) $trans['ddm'] : null,
                'encoding'              => $trans['encoding'] ?? null,
                'cable'                 => $trans['cable'] ?? $trans['cable_type'] ?? null,
                'distance'              => $trans['distance'] ?? $trans['reach'] ?? null,
                'wavelength'            => $trans['wavelength'] ?? null,
                'connector'             => $trans['connector'] ?? $trans['connector_type'] ?? null,
                'channels'              => $trans['channels'] ?? 1,
            ]);
        }

        return $transceivers;
    }
}
