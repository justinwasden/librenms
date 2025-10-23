<?php

namespace App\Services\RestApi\Contracts;

use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;

/**
 * Interface for vendor-specific REST API data processors
 *
 * Vendor processors handle vendor-specific data structures and endpoint patterns
 * that don't fit the generic mapping model. For example:
 * - PureStorage: network-interfaces endpoint with nested data structures
 * - FortiGate: system status with specific field transformations
 * - Cisco: RESTCONF endpoints with YANG model structures
 *
 * The processor chain works as follows:
 * 1. RestApiPollerService fetches data from REST API
 * 2. Tries each processor in priority order
 * 3. First processor where canProcess() returns true handles the data
 * 4. If no processor can handle it, GenericDataProcessor is used as fallback
 */
interface VendorDataProcessorInterface
{
    /**
     * Check if this processor can handle the given endpoint
     *
     * This method should check endpoint characteristics like:
     * - Path patterns (e.g., contains 'network-interfaces')
     * - Vendor name from template
     * - Specific data structure requirements
     *
     * @param RestApiEndpoint $endpoint The endpoint to check
     * @return bool True if this processor can handle the endpoint
     */
    public function canProcess(RestApiEndpoint $endpoint): bool;

    /**
     * Process the data from the endpoint
     *
     * This method receives the raw API response data and is responsible for:
     * - Parsing vendor-specific data structures
     * - Mapping to LibreNMS database tables (devices, ports, storage, sensors, etc.)
     * - Handling vendor-specific field transformations
     * - Creating/updating database records
     *
     * @param RestApiConnection $connection The REST API connection
     * @param RestApiEndpoint $endpoint The endpoint that was polled
     * @param array $data The JSON response data from the API
     * @return void
     */
    public function process(RestApiConnection $connection, RestApiEndpoint $endpoint, array $data): void;

    /**
     * Get the priority of this processor (lower = higher priority)
     *
     * Processors are evaluated in priority order:
     * - 1-49: Highest priority (reserved for critical vendor-specific handlers)
     * - 50-99: High priority (typical vendor processors)
     * - 100-499: Normal priority
     * - 500-998: Low priority
     * - 999: GenericDataProcessor (always last)
     *
     * @return int Priority value
     */
    public function getPriority(): int;

    /**
     * Get the vendor name this processor handles
     *
     * This is used for:
     * - Logging and debugging
     * - UI display
     * - Processor registration
     *
     * Should match vendor names in rest_api_templates table
     * Examples: 'purestorage', 'fortigate', 'cisco', 'meraki'
     *
     * @return string Vendor name (lowercase, alphanumeric)
     */
    public function getVendorName(): string;

    /**
     * Get a human-readable description of what this processor handles
     *
     * @return string Description for documentation/UI
     */
    public function getDescription(): string;
}
