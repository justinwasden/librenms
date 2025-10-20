<?php

namespace App\RestApi\Vendors;

use App\Models\Device;
use App\Models\RestApiEndpoint;
use App\RestApi\Vendors\Mappers\PureStorageMapper;
use App\RestApi\Vendors\Mappers\GenericMapper;
use Log;

/**
 * Factory for creating vendor-specific mappers
 * Uses registry pattern to manage vendor mappers
 */
class VendorMapperFactory
{
    /**
     * Registered vendor mappers
     *
     * @var array<string, VendorMapperInterface>
     */
    protected array $mappers = [];

    public function __construct()
    {
        // Register vendor mappers
        $this->register('purestorage', new PureStorageMapper());
        // Add more vendors as needed
        // $this->register('cisco', new CiscoMapper());
        // $this->register('dell', new DellMapper());
    }

    /**
     * Register a vendor mapper
     *
     * @param string $vendor Vendor identifier
     * @param VendorMapperInterface $mapper
     * @return void
     */
    public function register(string $vendor, VendorMapperInterface $mapper): void
    {
        $this->mappers[$vendor] = $mapper;
        Log::debug("Registered vendor mapper: {$vendor}");
    }

    /**
     * Get appropriate mapper for device/endpoint
     * Returns specific vendor mapper or falls back to generic mapper
     *
     * @param Device $device
     * @param RestApiEndpoint $endpoint
     * @return VendorMapperInterface
     */
    public function getMapper(Device $device, RestApiEndpoint $endpoint): VendorMapperInterface
    {
        // Try to find specific vendor mapper
        foreach ($this->mappers as $vendor => $mapper) {
            if ($mapper->canHandle($device, $endpoint)) {
                Log::debug("Using vendor mapper: {$vendor} for device {$device->hostname}");
                return $mapper;
            }
        }

        // Fallback to generic mapper
        Log::debug("Using generic vendor mapper for device {$device->hostname}");
        return new GenericMapper();
    }

    /**
     * Get all registered mappers
     *
     * @return array<string, VendorMapperInterface>
     */
    public function getMappers(): array
    {
        return $this->mappers;
    }

    /**
     * Get mapper by vendor name
     *
     * @param string $vendor
     * @return VendorMapperInterface|null
     */
    public function getMapperByVendor(string $vendor): ?VendorMapperInterface
    {
        return $this->mappers[$vendor] ?? null;
    }
}
