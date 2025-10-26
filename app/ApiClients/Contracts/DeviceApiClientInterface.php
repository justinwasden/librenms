<?php
namespace App\ApiClients\Contracts;

use App\Models\Device;

interface DeviceApiClientInterface
{
    // Short vendor identifier (e.g., 'purestorage', 'proxmox')
    public const VENDOR = 'generic';

    // Fast eligibility check (attribute or quick probe)
    public function supports(Device $device): bool;

    // Advertise which data types the client can provide
    // e.g., ['sensors','ports','mempools','processors','inventory','ipv4']
    public function capabilities(): array;

    // Fetch normalized data structures Modules expect
    public function fetchSensors(Device $device): array;
    public function fetchPorts(Device $device): array;
    public function fetchMempools(Device $device): array;
    public function fetchProcessors(Device $device): array;
    public function fetchInventory(Device $device): array;

    // IPv4 addresses (optional capability)
    // Return an array of entries with keys: ifIndex, ipv4_address, ipv4_prefixlen, context_name
    public function fetchIpv4Addresses(Device $device): array;

    /**
     * Low-level HTTP transport methods
     */

    /**
     * Perform a GET request to the API
     *
     * @param string $path The endpoint path
     * @param array $query Query parameters
     * @return array Decoded JSON response
     * @throws \RuntimeException On HTTP errors or invalid responses
     */
    public function get(string $path, array $query = []): array;

    /**
     * Perform a POST request to the API
     *
     * @param string $path The endpoint path
     * @param array $body Request body
     * @return array Decoded JSON response
     * @throws \RuntimeException On HTTP errors or invalid responses
     */
    public function post(string $path, array $body = []): array;

    /**
     * Test if the API is reachable and credentials are valid
     *
     * @return bool
     */
    public function isReachable(): bool;

    /**
     * Get API version and metadata information
     *
     * @return array Array with keys: version, vendor, api_version, etc.
     */
    public function getApiInfo(): array;
}