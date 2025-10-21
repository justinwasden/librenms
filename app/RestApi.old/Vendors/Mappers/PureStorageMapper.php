<?php

namespace App\RestApi\Vendors\Mappers;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\RestApi\Vendors\VendorMapperInterface;
use App\RestApi\DataProcessors\PureStorageDataProcessor;
use Illuminate\Support\Str;

/**
 * Pure Storage REST API Vendor Mapper
 *
 * Provides Pure Storage-specific mapping recommendations and validation.
 * Filtering and transformation logic moved to PureStorageDataProcessor.
 */
class PureStorageMapper implements VendorMapperInterface
{
    /**
     * Check if this mapper handles Pure Storage devices
     */
    public function canHandle(Device $device, RestApiEndpoint $endpoint): bool
    {
        return $device->os === 'purestorage';
    }

    /**
     * Analyze API response and recommend field mappings
     */
    public function getRecommendedMappings(array $apiResponse, RestApiEndpoint $endpoint): array
    {
        $recommendations = [];
        $items = $apiResponse['items'] ?? [];
        $sample = reset($items);

        if (!$sample) {
            return $recommendations;
        }

        // Volume endpoint recommendations
        if (Str::contains($endpoint->path, 'volumes')) {
            $recommendations = [
                'name' => [
                    'table' => 'storage',
                    'field' => 'storage_descr',
                    'confidence' => 0.99,
                    'reason' => 'Volume name maps to storage description',
                ],
                'provisioned' => [
                    'table' => 'storage',
                    'field' => 'storage_size',
                    'confidence' => 0.95,
                    'reason' => 'Provisioned space = total storage size',
                ],
                'space.total_used' => [
                    'table' => 'storage',
                    'field' => 'storage_used',
                    'confidence' => 0.95,
                    'reason' => 'Total used space',
                ],
            ];
        }

        // Network interface endpoint recommendations
        if (Str::contains($endpoint->path, 'network-interface')) {
            $recommendations = [
                'name' => [
                    'table' => 'ports',
                    'field' => 'ifName',
                    'confidence' => 0.99,
                    'reason' => 'Interface name',
                ],
                'enabled' => [
                    'table' => 'ports',
                    'field' => 'ifAdminStatus',
                    'confidence' => 0.90,
                    'reason' => 'Enabled status = admin status',
                ],
                'speed' => [
                    'table' => 'ports',
                    'field' => 'ifSpeed',
                    'confidence' => 0.85,
                    'reason' => 'Interface speed in bps',
                ],
            ];
        }

        return $recommendations;
    }

    /**
     * Validate if API field can map to database field
     */
    public function validateMapping(
        string $apiField,
        $apiValue,
        string $table,
        string $field
    ): array
    {
        return PureStorageDataProcessor::validate($apiField, $apiValue, $table, $field);
    }

    /**
     * Get compatible fields for table/datatype
     */
    public function getCompatibleFields(string $table, string $dataType): array
    {
        $fields = [
            'storage' => [
                'integer' => ['storage_size', 'storage_used', 'storage_free'],
                'string' => ['storage_descr', 'storage_type'],
                'float' => ['storage_perc'],
            ],
            'ports' => [
                'integer' => ['ifSpeed', 'ifAdminStatus'],
                'string' => ['ifName', 'ifDescr'],
                'float' => ['ifSpeed'],
            ],
        ];

        if (!isset($fields[$table][$dataType])) {
            return [];
        }

        $fieldList = $fields[$table][$dataType];
        return array_combine($fieldList, $fieldList);
    }
}