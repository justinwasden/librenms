<?php

/**
 * ESXi SOAP API Discovery Module
 *
 * Discovers hardware, network, performance, and storage information from standalone ESXi hosts
 * using the VMware vSphere SOAP API.
 *
 * This module only runs for vmware-esxi devices that have SOAP API configuration.
 */

use App\ApiClients\VMware\EsxiSoapClientFactory;
use App\Services\DeviceApiPersistor;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\Normalizers\EsxiSoapNormalizer;

$module_name = 'esxi-soap';

if ($device->os === 'vmware-esxi') {
    // Check for SOAP API configuration (schema_id 20 = esxi_soap)
    if (EsxiSoapClientFactory::hasConfig($device)) {
        echo "ESXi SOAP API: Discovering via SOAP API\n";

        try {
            // Initialize SOAP client using factory
            $client = EsxiSoapClientFactory::makeFromDevice($device);

            if (!$client) {
                echo "  ERROR: Could not create SOAP client\n";
                return;
            }

            // Test connection first
            if (!$client->testConnection()) {
                Log::warning("ESXi SOAP discovery failed: Unable to connect to {$device->hostname}");
                echo "  ERROR: Connection failed\n";
            } else {
                echo "  Connection successful\n";

                // 1. Fetch and update hardware information
                echo "  Fetching hardware info... ";
                $hardware = $client->fetchHostHardware($device);
                if (!empty($hardware)) {
                    // Update device table with hardware details
                    $updates = [];
                    if (isset($hardware['model'])) {
                        $updates['hardware'] = $hardware['model'];
                    }
                    if (isset($hardware['serial'])) {
                        $updates['serial'] = $hardware['serial'];
                    }
                    if (isset($hardware['version'])) {
                        $updates['version'] = $hardware['version'];
                    }
                    if (isset($hardware['full_name'])) {
                        $updates['features'] = $hardware['full_name'];
                    }

                    if (!empty($updates)) {
                        \DB::table('devices')
                            ->where('device_id', $device->device_id)
                            ->update($updates);
                        echo "OK\n";
                    } else {
                        echo "No data\n";
                    }

                    // Save inventory
                    $normalizedInventory = EsxiSoapNormalizer::normalizeInventory($device, $hardware);
                    if (!empty($normalizedInventory)) {
                        DeviceApiPersistor::saveInventory($device, $normalizedInventory);
                        echo "  Saved " . count($normalizedInventory) . " inventory items\n";
                    }
                } else {
                    echo "Failed\n";
                }

                // 2. Fetch and save network interfaces
                echo "  Fetching network interfaces... ";
                $interfaces = $client->fetchNetworkInterfaces($device);
                if (!empty($interfaces)) {
                    $normalizedPorts = EsxiSoapNormalizer::normalizeNetworkInterfaces($device, $interfaces);
                    DeviceApiPersistor::savePorts($device, $normalizedPorts);
                    echo "OK (" . count($normalizedPorts) . " ports)\n";
                } else {
                    echo "No interfaces found\n";
                }

                // 3. Fetch and save performance metrics
                echo "  Fetching performance metrics... ";
                $performance = $client->fetchHostPerformance($device);
                if (!empty($performance)) {
                    // Save as sensors
                    $normalizedSensors = EsxiSoapNormalizer::normalizePerformance($device, $performance);
                    if (!empty($normalizedSensors)) {
                        DeviceApiPersistor::saveSensors($device, $normalizedSensors);
                        echo "OK (" . count($normalizedSensors) . " sensors)\n";
                    }

                    // Save as processors
                    $normalizedProcessors = EsxiSoapNormalizer::normalizeProcessors($device, $performance);
                    if (!empty($normalizedProcessors)) {
                        DeviceApiPersistor::saveProcessors($device, $normalizedProcessors);
                        echo "  Saved processor metrics\n";
                    }

                    // Save as mempools
                    $normalizedMempools = EsxiSoapNormalizer::normalizeMempools($device, $performance);
                    if (!empty($normalizedMempools)) {
                        DeviceApiPersistor::saveMempools($device, $normalizedMempools);
                        echo "  Saved memory pool metrics\n";
                    }
                } else {
                    echo "Failed\n";
                }

                // 4. Fetch and save datastores
                echo "  Fetching datastores... ";
                $datastores = $client->fetchDatastores($device);
                if (!empty($datastores)) {
                    $normalizedStorage = EsxiSoapNormalizer::normalizeDatastores($device, $datastores);
                    DeviceApiPersistor::saveStorage($device, $normalizedStorage);
                    echo "OK (" . count($normalizedStorage) . " datastores)\n";
                } else {
                    echo "No datastores found\n";
                }

                echo "ESXi SOAP API: Discovery completed successfully\n";
            }
        } catch (\Exception $e) {
            Log::error("ESXi SOAP discovery failed for device {$device->device_id}: {$e->getMessage()}");
            echo "  ERROR: {$e->getMessage()}\n";
        }
    } else {
        d_echo("ESXi SOAP API: No SOAP configuration found for device {$device->device_id}\n");
    }
}

unset($apiConfig, $client, $hardware, $interfaces, $performance, $datastores);
