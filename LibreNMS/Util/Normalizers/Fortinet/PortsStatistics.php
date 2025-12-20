<?php

namespace LibreNMS\Util\Normalizers\Fortinet;

use App\Models\Device;
use LibreNMS\Util\Normalizers\BaseNormalizer;

/**
 * Fortinet - PortsStatistics Normalizer
 *
 * Capability: ports
 * Vendor: fortigate
 */
class PortsStatistics extends BaseNormalizer
{
    protected string $capability = 'ports';
    protected string $vendor = 'fortigate';

    protected function doNormalize(Device $device, array $payload): array
    {
return $this->normalizeFortigateInterfaceStats($payload);
    }
}
