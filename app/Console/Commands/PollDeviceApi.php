<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Services\DeviceApiExecutor;
use LibreNMS\Util\ApiTemplateManager;
use LibreNMS\Util\DeviceApiSettings;

class PollDeviceApi extends Command
{
    protected $signature = 'device:poll-api {device_id}';
    protected $description = 'Poll REST API endpoints for a device using the selected template';

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device_id');
        $device = Device::find($deviceId);
        if (!$device) {
            $this->error("Device $deviceId not found.");
            return 1;
        }

        $tplKey = $device->getAttrib('rest_template_key');
        if (!$tplKey) {
            $this->warn("Device {$deviceId} has no rest_template_key set.");
            return 0;
        }

        // Resolve base URL into device attribs
        DeviceApiSettings::ensureResolvedBaseUrl($device);

        $tpl = ApiTemplateManager::loadTemplate($tplKey);
        if (!$tpl) {
            $this->error("Template {$tplKey} not found or disabled.");
            return 1;
        }

        // Instantiate a vendor-specific client
        $client = $this->makeClient($device, $tpl);

        // Execute endpoints
        $executor = new DeviceApiExecutor();
        try {
            $executor->run($device, $tplKey, $client);
            $this->info("API poll successful for device {$deviceId}.");
            return 0;
        } catch (\Throwable $e) {
            $this->error("API poll failed: " . $e->getMessage());
            return 1;
        }
    }

    protected function makeClient(Device $device, array $tpl)
    {
        return match ($tpl['vendor']) {
            'proxmox_ve_token', 'proxmox_ve_ticket' => new \App\ApiClients\Proxmox\ProxmoxApiClient($device),
            'purestorage_flasharray' => new \App\ApiClients\PureStorage\FlashArrayClient($device, [
                'strategy_key' => $tpl['auth_type'],
            ]),
            'vmware_vcenter' => new \App\ApiClients\Vmware\VcenterClient($device), // implement client
            'netapp_ontap' => new \App\ApiClients\Netapp\OntapClient($device),     // implement client
            'dellemc_unity' => new \App\ApiClients\Dell\UnityClient($device),      // implement client
            'dellemc_isilon' => new \App\ApiClients\Dell\IsilonClient($device),    // implement client
            default => new \App\ApiClients\Generic\RestClient($device),            // implement generic client
        };
    }
}