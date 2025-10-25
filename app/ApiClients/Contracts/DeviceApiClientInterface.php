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
}