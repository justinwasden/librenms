<?php

declare(strict_types=1);

namespace LibreNMS\OS;

use App\Models\DeviceAttrib;
use App\Models\EntPhysical;
use Illuminate\Support\Facades\Log;
use LibreNMS\Util\File;
use LibreNMS\Util\Process;
use LibreNMS\Util\Rrd;

class Purestorage extends Generic
{
    protected string $os = 'purestorage';

    public function poll_os(): void
    {
        // === SNMP Polling ===
        $this->pollSnmp();

        // === SSH Polling ===
        $this->pollSsh();
    }

    private function pollSnmp(): void
    {
        $oids = [
            'purestorage_bandwidth' => [
                'read'  => '.1.3.6.1.4.1.40482.4.1.0',
                'write' => '.1.3.6.1.4.1.40482.4.2.0',
            ],
            'purestorage_iops' => [
                'read'  => '.1.3.6.1.4.1.40482.4.3.0',
                'write' => '.1.3.6.1.4.1.40482.4.4.0',
            ],
            'purestorage_latency' => [
                'read'  => '.1.3.6.1.4.1.40482.4.5.0',
                'write' => '.1.3.6.1.4.1.40482.4.6.0',
            ],
        ];

        $snmp_oids = [];
        foreach ($oids as $rrd_file => $ds_oids) {
            foreach ($ds_oids as $ds => $oid) {
                $snmp_oids[] = $oid;
            }
        }

        $snmp_data = $this->device->getSnmp()->getMulti($snmp_oids, '-OQUs', 'PURESTORAGE-MIB');

        if ($snmp_data) {
            foreach ($oids as $rrd_file => $ds_oids) {
                $rrd_values = [];
                $valid = true;
                foreach ($ds_oids as $ds => $oid) {
                    if (isset($snmp_data[$oid]['value']) && is_numeric($snmp_data[$oid]['value'])) {
                        $rrd_values[] = $snmp_data[$oid]['value'];
                    } else {
                        $valid = false;
                        break;
                    }
                }

                if ($valid) {
                    Rrd::update($this->device, $rrd_file, $rrd_values);
                    $this->device->graphs['device_' . $rrd_file] = 1;
                }
            }
        }
    }

    private function pollSsh(): void
    {
        $ssh_credentials = $this->device->getSshCredentials();
        if (empty($ssh_credentials['username'])) {
            // No SSH credentials configured for this device, so we skip.
            return;
        }

        $hostname = $this->device->hostname;
        $username = $ssh_credentials['username'];
        $password = $ssh_credentials['password'] ?? null;
        $private_key = $this->device->ssh_private_key; // The model will decrypt this automatically

        $script = config('librenms.install_dir') . '/scripts/agent-local/purestorage-ssh/purestorage-ssh.py';
        $command = "{$script} " . escapeshellarg($hostname) . " " . escapeshellarg($username);

        $env = [];
        $key_file = null;

        try {
            if ($private_key) {
                $key_file = tempnam(sys_get_temp_dir(), 'purekey_');
                file_put_contents($key_file, $private_key);
                chmod($key_file, 0600);
                $command .= ' --key-file ' . escapeshellarg($key_file);
            } elseif ($password) {
                $env['PURE_PASSWORD'] = $password;
            }

            $output = Process::run($command, [], $env);

            if ($output['exit_code'] !== 0) {
                Log::error("Pure Storage SSH script failed for device {$this->device->device_id}: " . $output['stderr']);
                return;
            }

            $data = json_decode($output['stdout'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Failed to decode JSON from Pure Storage SSH script for device {$this->device->device_id}: " . json_last_error_msg());
                Log::debug("Invalid JSON received: " . $output['stdout']);
                return;
            }

            if (empty($data)) {
                Log::debug("Pure Storage SSH script returned empty data for device {$this->device->device_id}.");
                return;
            }

            // -- Process Volume Performance --
            if (isset($data['volumes']) && is_array($data['volumes'])) {
                foreach ($data['volumes'] as $volume) {
                    $instance = $volume['Name'];
                    $this->device->graphs['device_purestorage_volume_iops-' . $instance] = 1;
                    $this->device->graphs['device_purestorage_volume_bandwidth-' . $instance] = 1;
                    $this->device->graphs['device_purestorage_volume_latency-' . $instance] = 1;

                    Rrd::update($this->device, "purestorage-volume-iops-{$instance}", [$volume['Reads/s'], $volume['Writes/s']], 'GAUGE');
                    Rrd::update($this->device, "purestorage-volume-bandwidth-{$instance}", [$volume['Output/s'], $volume['Input/s']], 'GAUGE');
                    Rrd::update($this->device, "purestorage-volume-latency-{$instance}", [$volume['Usec/Read'], $volume['Usec/Write']], 'GAUGE');
                }
            }

            // -- Process Hardware Inventory --
            if (isset($data['hardware']) && is_array($data['hardware'])) {
                foreach ($data['hardware'] as $index => $hw) {
                    $ent = [
                        'entPhysicalIndex' => $index + 1000,
                        'entPhysicalDescr' => $hw['Name'],
                        'entPhysicalClass' => 'chassis',
                        'entPhysicalModel' => $hw['Type'] ?? '',
                        'entPhysicalSerialNum' => $hw['Serial'] ?? '',
                        'entPhysicalFirmwareRev' => $hw['Version'] ?? '',
                        'entPhysicalContainedIn' => 0,
                        'entPhysicalParentRelPos' => -1,
                        'entPhysicalOperStatus' => ($hw['Status'] === 'ok') ? 'ok' : 'degraded',
                    ];
                    EntPhysical::updateOrCreate(
                        ['device_id' => $this->device->device_id, 'entPhysicalIndex' => $ent['entPhysicalIndex']],
                        $ent
                    );
                }
            }

            // -- Store other info as attributes --
            if (isset($data['array'])) {
                DeviceAttrib::updateOrCreate(
                    ['device_id' => $this->device->device_id, 'attrib_type' => 'pure_array_info'],
                    ['attrib_value' => json_encode($data['array'])]
                );
            }

            if (isset($data['connections'])) {
                DeviceAttrib::updateOrCreate(
                    ['device_id' => $this->device->device_id, 'attrib_type' => 'pure_connections'],
                    ['attrib_value' => json_encode($data['connections'])]
                );
            }
        } finally {
            // Always clean up the temporary key file
            if ($key_file && file_exists($key_file)) {
                unlink($key_file);
            }
        }
    }
}