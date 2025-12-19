<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VmwareSnapshotAlertRulesSeeder extends Seeder
{
    /**
     * Seed VMware VM Snapshot Alert Rules
     */
    public function run(): void
    {
        $now = now();

        // Alert Rule 1: VMs with excessive snapshots (more than 5)
        $excessiveSnapshotsRule = [
            'name' => 'VMware: VM has excessive snapshots',
            'query' => 'SELECT vm_name, snapshot_count, devices.hostname FROM vmware_vm_snapshots JOIN devices ON vmware_vm_snapshots.device_id = devices.device_id WHERE devices.os IN (\'vmware-vcsa\', \'vmware\') AND snapshot_count > 5',
            'severity' => 'warning',
            'disabled' => 0,
            'extra' => '',
            'proc' => '',
            'invert_map' => 0,
            'notes' => 'Alerts when a VM has more than 5 snapshots. Excessive snapshots can impact performance and disk space.',
            'builder' => json_encode(['condition' => 'AND', 'rules' => [], 'valid' => true]),
        ];

        DB::table('alert_rules')->updateOrInsert(
            ['name' => $excessiveSnapshotsRule['name']],
            $excessiveSnapshotsRule
        );

        // Alert Rule 2: VMs with old snapshots (older than 30 days)
        $oldSnapshotsRule = [
            'name' => 'VMware: VM has snapshots older than 30 days',
            'query' => 'SELECT vm_name, snapshot_count, oldest_snapshot_date, DATEDIFF(NOW(), oldest_snapshot_date) as days_old, devices.hostname FROM vmware_vm_snapshots JOIN devices ON vmware_vm_snapshots.device_id = devices.device_id WHERE devices.os IN (\'vmware-vcsa\', \'vmware\') AND oldest_snapshot_date < DATE_SUB(NOW(), INTERVAL 30 DAY)',
            'severity' => 'warning',
            'disabled' => 0,
            'extra' => '',
            'proc' => '',
            'invert_map' => 0,
            'notes' => 'Alerts when a VM has snapshots older than 30 days. Old snapshots should be reviewed and consolidated.',
            'builder' => json_encode(['condition' => 'AND', 'rules' => [], 'valid' => true]),
        ];

        DB::table('alert_rules')->updateOrInsert(
            ['name' => $oldSnapshotsRule['name']],
            $oldSnapshotsRule
        );

        // Alert Rule 3: VMs with very old snapshots (older than 90 days) - Critical
        $veryOldSnapshotsRule = [
            'name' => 'VMware: VM has snapshots older than 90 days',
            'query' => 'SELECT vm_name, snapshot_count, oldest_snapshot_date, DATEDIFF(NOW(), oldest_snapshot_date) as days_old, devices.hostname FROM vmware_vm_snapshots JOIN devices ON vmware_vm_snapshots.device_id = devices.device_id WHERE devices.os IN (\'vmware-vcsa\', \'vmware\') AND oldest_snapshot_date < DATE_SUB(NOW(), INTERVAL 90 DAY)',
            'severity' => 'critical',
            'disabled' => 0,
            'extra' => '',
            'proc' => '',
            'invert_map' => 0,
            'notes' => 'CRITICAL: VM has snapshots older than 90 days. These must be consolidated immediately to prevent storage and performance issues.',
            'builder' => json_encode(['condition' => 'AND', 'rules' => [], 'valid' => true]),
        ];

        DB::table('alert_rules')->updateOrInsert(
            ['name' => $veryOldSnapshotsRule['name']],
            $veryOldSnapshotsRule
        );

        $this->command->info('VMware VM Snapshot alert rules have been seeded.');
    }
}
