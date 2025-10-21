<?php

namespace App\RestApi\Vendors;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * VendorMapperRegistry
 *
 * Discovers and manages all available vendor mappers.
 * Scans the Mappers directory for implementations of VendorMapperInterface
 * and provides methods to retrieve mappers by vendor, OS pattern, or list all available.
 */
class VendorMapperRegistry
{
    private static array $mappers = [];
    private static bool $initialized = false;

    /**
     * Initialize registry by discovering all vendor mappers
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        $mappersPath = app_path('RestApi/Vendors/Mappers');

        if (! File::isDirectory($mappersPath)) {
            self::$initialized = true;
            return;
        }

        $files = File::files($mappersPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip the base interface and this registry
            if (in_array($file->getFilenameWithoutExtension(), ['VendorMapperInterface', 'VendorMapperRegistry'])) {
                continue;
            }

            $className = 'App\\RestApi\\Vendors\\Mappers\\' . $file->getFilenameWithoutExtension();

            try {
                if (class_exists($className)) {
                    $reflection = new ReflectionClass($className);

                    // Check if class implements VendorMapperInterface
                    if ($reflection->implementsInterface(VendorMapperInterface::class)) {
                        $instance = app($className);
                        $vendorName = $instance->getVendorName();
                        self::$mappers[$vendorName] = $instance;
                    }
                }
            } catch (\Exception $e) {
                \Log::warning("Failed to load vendor mapper: {$className}", ['error' => $e->getMessage()]);
            }
        }

        self::$initialized = true;
    }

    /**
     * Get a mapper by vendor name
     *
     * @param string $vendorName Vendor name (e.g., "Pure Storage")
     * @return VendorMapperInterface|null
     */
    public static function getMapper(string $vendorName): ?VendorMapperInterface
    {
        self::initialize();

        return self::$mappers[$vendorName] ?? null;
    }

    /**
     * Get a mapper by OS pattern
     * Searches all mappers to find one that matches the OS pattern
     *
     * @param string $osPattern OS string (e.g., "Purity//FA 6.4.10")
     * @return VendorMapperInterface|null
     */
    public static function getMapperByOsPattern(string $osPattern): ?VendorMapperInterface
    {
        self::initialize();

        foreach (self::$mappers as $mapper) {
            foreach ($mapper->getOsPatterns() as $pattern) {
                if (Str::is($pattern, $osPattern)) {
                    return $mapper;
                }
            }
        }

        return null;
    }

    /**
     * Get all available mappers
     *
     * @return array Associative array of vendor => mapper
     */
    public static function getAllMappers(): array
    {
        self::initialize();

        return self::$mappers;
    }

    /**
     * Get list of vendor names with available mappers
     *
     * @return array List of vendor names
     */
    public static function getAvailableVendors(): array
    {
        self::initialize();

        return array_keys(self::$mappers);
    }

    /**
     * Check if a mapper exists for a vendor
     *
     * @param string $vendorName Vendor name
     * @return bool
     */
    public static function hasMapper(string $vendorName): bool
    {
        self::initialize();

        return isset(self::$mappers[$vendorName]);
    }

    /**
     * Register a custom mapper
     * Allows programmatic registration of mappers
     *
     * @param VendorMapperInterface $mapper
     */
    public static function registerMapper(VendorMapperInterface $mapper): void
    {
        self::initialize();

        self::$mappers[$mapper->getVendorName()] = $mapper;
    }

    /**
     * Get mapper info for UI display
     *
     * @return array Array of vendor info
     */
    public static function getMapperInfo(): array
    {
        self::initialize();

        $info = [];

        foreach (self::$mappers as $vendorName => $mapper) {
            $info[] = [
                'vendor' => $vendorName,
                'description' => $mapper->getDescription(),
                'version' => $mapper->getVersion(),
                'os_patterns' => $mapper->getOsPatterns(),
            ];
        }

        return $info;
    }

    /**
     * Clear registry (useful for testing)
     */
    public static function clear(): void
    {
        self::$mappers = [];
        self::$initialized = false;
    }
}
