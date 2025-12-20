<?php

/**
 * VmwareEsxi.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2023 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\OS;

use App\ApiClients\VMware\EsxiSoapClient;
use App\ApiClients\VMware\EsxiSoapClientFactory;
use App\Services\DeviceApiPersistor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Discovery\VminfoDiscovery;
use LibreNMS\Interfaces\Polling\VminfoPolling;
use LibreNMS\OS\Traits\ApiPolling;
use LibreNMS\OS\Traits\VminfoVmware;
use LibreNMS\Util\Normalizers\EsxiSoapNormalizer;

class VmwareEsxi extends \LibreNMS\OS implements VminfoDiscovery, VminfoPolling, ProcessorDiscovery
{
    use ApiPolling;
    use VminfoVmware;

    /**
     * Override discoverVmInfo to use SOAP API when available, otherwise fall back to SNMP
     */
    public function discoverVmInfo(): Collection
    {
        $soapClient = $this->getSoapClient();
        if ($soapClient) {
            try {
                // Use SOAP API to fetch VMs
                $vmsArray = $soapClient->fetchVms($this->getDevice());

                // Convert array to Collection of Vminfo models
                $vms = collect();
                foreach ($vmsArray as $vmData) {
                    $vms->push(new \App\Models\Vminfo($vmData));
                }

                Log::info("VmwareEsxi: Discovered " . $vms->count() . " VMs via SOAP API");
                return $vms;
            } catch (\Exception $e) {
                Log::warning("VmwareEsxi: SOAP VM discovery failed, falling back to SNMP: {$e->getMessage()}");
            }
        }

        // Fall back to SNMP-based discovery from VminfoVmware trait
        return parent::discoverVmInfo();
    }

    public function pollVminfo(Collection $vms): Collection
    {
        // no VMs, assume there aren't any
        if ($vms->isEmpty()) {
            return $vms;
        }

        return $this->discoverVmInfo(); // just do the same thing as discovery.
    }

    /**
     * Discover processors using SOAP API if configured
     *
     * @return array
     */
    public function discoverProcessors()
    {
        $soapClient = $this->getSoapClient();
        if (!$soapClient) {
            return []; // Fall back to default discovery (SNMP)
        }

        try {
            $performance = $soapClient->fetchHostPerformance($this->getDevice());
            $processors = EsxiSoapNormalizer::normalizeProcessors($this->getDevice(), $performance);

            $discovered = [];
            foreach ($processors as $proc) {
                $discovered[] = Processor::discover(
                    $proc['processor_descr'] ?? 'CPU',
                    $proc['processor_index'] ?? 0,
                    $proc['processor_type'] ?? 'esxi-soap',
                    $proc['processor_usage'] ?? 0
                );
            }

            return $discovered;
        } catch (\Exception $e) {
            Log::error("VmwareEsxi: SOAP processor discovery failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Discover ESXi host details using SOAP API during OS discovery
     *
     * This method is automatically called during device discovery
     */
    public function discoverOS($device): void
    {
        parent::discoverOS($device);

        $soapClient = $this->getSoapClient();
        if (!$soapClient) {
            return; // No SOAP config, skip
        }

        try {
            // Fetch hardware information
            $hardware = $soapClient->fetchHostHardware($this->getDevice());
            if (!empty($hardware)) {
                // Update device attributes
                if (isset($hardware['model'])) {
                    $device->hardware = $hardware['model'];
                }
                if (isset($hardware['serial'])) {
                    $device->serial = $hardware['serial'];
                }
                if (isset($hardware['version'])) {
                    $device->version = $hardware['version'];
                }
                if (isset($hardware['full_name'])) {
                    $device->features = $hardware['full_name'];
                }

                // Save inventory
                $inventory = EsxiSoapNormalizer::normalizeInventory($this->getDevice(), $hardware);
                if (!empty($inventory)) {
                    DeviceApiPersistor::saveInventory($this->getDevice(), $inventory);
                }
            }

            // Fetch and save network interfaces
            $interfaces = $soapClient->fetchNetworkInterfaces($this->getDevice());
            if (!empty($interfaces)) {
                $ports = EsxiSoapNormalizer::normalizeNetworkInterfaces($this->getDevice(), $interfaces);
                DeviceApiPersistor::savePorts($this->getDevice(), $ports);
            }

            // Fetch and save datastores
            $datastores = $soapClient->fetchDatastores($this->getDevice());
            if (!empty($datastores)) {
                $storage = EsxiSoapNormalizer::normalizeDatastores($this->getDevice(), $datastores);
                DeviceApiPersistor::saveStorage($this->getDevice(), $storage);
            }

            Log::info("VmwareEsxi: SOAP API discovery completed for device {$device->device_id}");
        } catch (\Exception $e) {
            Log::error("VmwareEsxi: SOAP discovery failed: {$e->getMessage()}");
        }
    }

    /**
     * Poll ESXi host performance metrics using SOAP API
     *
     * This method is automatically called during device polling
     */
    public function pollOS(): void
    {
        parent::pollOS();

        $soapClient = $this->getSoapClient();
        if (!$soapClient) {
            return; // No SOAP config, skip
        }

        try {
            // Fetch performance metrics
            $performance = $soapClient->fetchHostPerformance($this->getDevice());
            if (!empty($performance)) {
                // Save sensors
                $sensors = EsxiSoapNormalizer::normalizePerformance($this->getDevice(), $performance);
                if (!empty($sensors)) {
                    DeviceApiPersistor::saveSensors($this->getDevice(), $sensors);
                }

                // Save mempools
                $mempools = EsxiSoapNormalizer::normalizeMempools($this->getDevice(), $performance);
                if (!empty($mempools)) {
                    DeviceApiPersistor::saveMempools($this->getDevice(), $mempools);
                }
            }

            Log::debug("VmwareEsxi: SOAP API polling completed for device {$this->getDeviceId()}");
        } catch (\Exception $e) {
            Log::error("VmwareEsxi: SOAP polling failed: {$e->getMessage()}");
        }
    }

    /**
     * Get SOAP client if configured for this device
     *
     * @return EsxiSoapClient|null
     */
    protected function getSoapClient(): ?EsxiSoapClient
    {
        if (!$this->hasApiConfig()) {
            return null;
        }

        return \App\ApiClients\VMware\EsxiSoapClientFactory::makeFromDevice($this->getDevice());
    }
}
