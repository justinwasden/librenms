<?php

namespace App\RestApi\Vendors\Mappers;

use App\RestApi\Vendors\VendorMapperInterface;

/**
 * GenericMapper
 *
 * Generic mapper for user-defined or custom vendor mappings
 * Allows users to create their own mapping configurations
 * without needing to create a new vendor mapper class
 */
class GenericMapper implements VendorMapperInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getVendorName(): string
    {
        return $this->config['vendor_name'] ?? 'Generic REST API';
    }

    public function getOsPatterns(): array
    {
        return $this->config['os_patterns'] ?? [];
    }

    public function getDescription(): string
    {
        return $this->config['description'] ?? 'User-defined REST API mapping';
    }

    public function getVersion(): string
    {
        return $this->config['version'] ?? '1.0.0';
    }

    public function getMappings(): array
    {
        return $this->config['mappings'] ?? [];
    }

    public function getMappingsForEndpoint(string $endpoint): array
    {
        return $this->getMappings()[$endpoint] ?? [];
    }

    public function getTargetTableForEndpoint(string $endpoint): string
    {
        return $this->config['endpoint_targets'][$endpoint] ?? 'sensors';
    }

    public function transformValue(string $field, mixed $value): mixed
    {
        // Allow custom transformations if defined
        if (isset($this->config['transformers'][$field])) {
            return call_user_func($this->config['transformers'][$field], $value);
        }

        return $value;
    }

    public function isValidMapping(string $endpoint, string $field, string $targetTable): bool
    {
        $mappings = $this->getMappingsForEndpoint($endpoint);

        return isset($mappings[$field]);
    }

    public function getSensorClass(string $endpoint, string $sensorDescr): ?string
    {
        // Check if sensor class is defined in config
        if (isset($this->config['sensor_classes'][$endpoint][$sensorDescr])) {
            return $this->config['sensor_classes'][$endpoint][$sensorDescr];
        }

        // Try to infer from sensor description
        $lowerDescr = strtolower($sensorDescr);
        if (preg_match('/(temperature|temp)/i', $lowerDescr)) {
            return 'temperature';
        }
        if (preg_match('/(voltage|volt|power|watt)/i', $lowerDescr)) {
            return 'power';
        }
        if (preg_match('/(current|amp|amps)/i', $lowerDescr)) {
            return 'current';
        }
        if (preg_match('/(frequency|hz)/i', $lowerDescr)) {
            return 'frequency';
        }

        // Default to gauge
        return 'gauge';
    }

    public function getSensorDescription(string $endpoint, string $apiField): string
    {
        // Check if description is defined in config
        if (isset($this->config['sensor_descriptions'][$endpoint][$apiField])) {
            return $this->config['sensor_descriptions'][$endpoint][$apiField];
        }

        // Convert API field name to readable format
        // Convert snake_case to Title Case
        $desc = str_replace(['_', '.'], ' ', $apiField);
        return ucwords($desc);
    }
}
