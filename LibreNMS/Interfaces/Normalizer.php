<?php

namespace LibreNMS\Interfaces;

use App\Models\Device;

/**
 * Interface for data normalizers that transform vendor API responses
 * into LibreNMS-compatible data structures.
 */
interface Normalizer
{
    /**
     * Normalize raw API payload into LibreNMS format
     *
     * @param Device $device The device being polled
     * @param array $payload Raw API response data
     * @return array Normalized data in LibreNMS format
     */
    public function normalize(Device $device, array $payload): array;

    /**
     * Get the capability this normalizer produces
     * Examples: 'sensors', 'ports', 'inventory', 'processors'
     *
     * @return string
     */
    public function getCapability(): string;
}
