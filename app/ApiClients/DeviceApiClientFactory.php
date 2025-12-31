<?php
namespace App\ApiClients;

use App\Models\Device;
use App\ApiClients\Contracts\DeviceApiClientInterface;
use Illuminate\Support\Facades\Log;

class DeviceApiClientFactory
{
    /**
     * Mapping of template keys to client classes
     */
    protected static array $templateToClient = [
        'cisco_ftd' => \App\ApiClients\Cisco\FtdApiClient::class,
        'cisco_ucsm' => \App\ApiClients\Cisco\UcsmXmlClient::class,
        'fortigate' => \App\ApiClients\Fortinet\FortiGateClient::class,
        'fortinet_fortigate' => \App\ApiClients\Fortinet\FortiGateClient::class,
        'netapp' => \App\ApiClients\NetApp\OntapClient::class,
        'netapp_ontap' => \App\ApiClients\NetApp\OntapClient::class,
        'proxmox' => \App\ApiClients\Proxmox\ProxmoxApiClient::class,
        'purestorage_flasharray' => \App\ApiClients\PureStorage\FlashArrayClient::class,
        'purestorage' => \App\ApiClients\PureStorage\FlashArrayClient::class,
        'proxmox_ve' => \App\ApiClients\Proxmox\ProxmoxApiClient::class,
        'velocloud' => \App\ApiClients\VMware\VeloCloudClient::class,
        'vmware_vcenter' => \App\ApiClients\VMware\VCenterClient::class,
        'vmware_vcenter_default' => \App\ApiClients\VMware\VCenterClient::class,
        'vmware_velocloud' => \App\ApiClients\VMware\VeloCloudClient::class,
        'vmware_esxi' => \App\ApiClients\VMware\EsxiClient::class,
        'vcenter_soap' => \App\ApiClients\VMware\VCenterSoapClient::class,
        // Note: esxi_soap uses GenericDeviceApiClient + SOAP endpoints via DeviceApiExecutor
    ];

    /**
     * Register vendor client classes here for auto-detection.
     * Each must implement DeviceApiClientInterface and define a VENDOR constant.
     * GenericDeviceApiClient should be LAST as it's the fallback for any template.
     */
    protected static array $clientClasses = [
        \App\ApiClients\PureStorage\FlashArrayClient::class,
        \App\ApiClients\Proxmox\ProxmoxApiClient::class,
        \App\ApiClients\VMware\VCenterClient::class,
        \App\ApiClients\VMware\VeloCloudClient::class,
        \App\ApiClients\VMware\EsxiClient::class,
        \App\ApiClients\Fortinet\FortiGateClient::class,
        \App\ApiClients\NetApp\OntapClient::class,
        \App\ApiClients\Cisco\UcsmXmlClient::class,
        \App\ApiClients\Cisco\FtdApiClient::class,
        \App\ApiClients\GenericDeviceApiClient::class, // Fallback for templates without specific clients
    ];

    protected static array $cache = []; // device_id => class-string

    public static function make(Device $device): ?DeviceApiClientInterface
    {
        $id = (int) ($device->device_id ?? 0);
        if ($id && isset(self::$cache[$id])) {
            $class = self::$cache[$id];
            return new $class($device);
        }

        // Get template key from device attributes
        $templateKey = $device->getAttrib('api_template_key') ?: $device->getAttrib('api_template');

        if ($templateKey && isset(self::$templateToClient[$templateKey])) {
            $class = self::$templateToClient[$templateKey];
            self::$cache[$id] = $class;
            return new $class($device);
        }

        // Probe path: ask each client if it supports this device
        foreach (self::$clientClasses as $class) {
            try {
                $client = new $class($device);
                if ($client->supports($device)) {
                    self::$cache[$id] = $class;
                    return $client;
                }
            } catch (\Throwable $e) {
                // For VMware clients, session failures are expected when probing non-VMware devices
                if ($class === \App\ApiClients\VMware\VCenterClient::class) {
                    Log::debug("API client probe skipped VCenterClient for device $id: not a vCenter device");
                } elseif ($class === \App\ApiClients\VMware\VeloCloudClient::class) {
                    Log::debug("API client probe skipped VeloCloudClient for device $id: not a VeloCloud device");
                } else {
                    Log::debug("API client probe failed for $class on device $id: " . $e->getMessage());
                }
            }
        }

        return null;
    }
}