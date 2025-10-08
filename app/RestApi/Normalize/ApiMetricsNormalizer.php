<?php
namespace App\RestApi\Normalize;

use App\RestApi\Utils\JsonFlattener;

class ApiMetricsNormalizer
{
    public static function normalize(string $vendor, string $resourceType, array $rawMetrics): array
    {
        $normalizerClass = __NAMESPACE__ . "\\Normalize" . ucfirst($resourceType);
        if (class_exists($normalizerClass)) {
            return $normalizerClass::normalize($rawMetrics, $vendor);
        }

        // Generic fallback
        $flattened = JsonFlattener::flatten($rawMetrics, $resourceType);
        $normalized = [];
        foreach ($flattened as $k => $v) {
            $normalized[] = [
                'name' => $k,
                'value' => $v,
                'unit' => '',
                'resource_type' => $resourceType,
                'vendor' => $vendor,
            ];
        }
        return $normalized;
    }
}
