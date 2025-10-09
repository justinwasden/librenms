<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\RestApiMetricFieldMapping;
use Illuminate\Console\Command;

class DiagnoseRestApiMappings extends Command
{
    protected $signature = 'restapi:diagnose-mappings {device_id}';
    protected $description = 'Diagnose REST API mappings for a device';

    public function handle()
    {
        $deviceId = $this->argument('device_id');
        $device = Device::find($deviceId);
        
        if (!$device) {
            $this->error("Device {$deviceId} not found");
            return 1;
        }
        
        $this->info("Diagnosing REST API mappings for: {$device->hostname}");
        $this->line("");
        
        // Get all mappings grouped by table
        $mappings = RestApiMetricFieldMapping::where(function($q) use ($deviceId) {
            $q->whereNull('device_id')->orWhere('device_id', $deviceId);
        })->get()->groupBy('librenms_table');
        
        $this->info("=== Mappings by Table ===");
        foreach ($mappings as $table => $tableMappings) {
            $enabled = $tableMappings->where('enabled', true)->count();
            $disabled = $tableMappings->where('enabled', false)->count();
            
            $this->line("");
            $this->info("Table: {$table}");
            $this->line("  Total: " . $tableMappings->count() . " (Enabled: {$enabled}, Disabled: {$disabled})");
            
            if ($enabled > 0) {
                $this->line("  Enabled mappings:");
                foreach ($tableMappings->where('enabled', true) as $mapping) {
                    $this->line("    - {$mapping->api_field_name} -> {$mapping->librenms_field} (confidence: {$mapping->confidence_score})");
                }
            }
        }
        
        // Check for common storage fields
        $this->line("");
        $this->info("=== Storage Field Analysis ===");
        $storageFields = ['storage_size', 'storage_used', 'storage_free', 'storage_perc', 'storage_units'];
        foreach ($storageFields as $field) {
            $count = RestApiMetricFieldMapping::where('librenms_table', 'storage')
                ->where('librenms_field', $field)
                ->where('enabled', true)
                ->count();
            $this->line("  {$field}: {$count} mappings");
        }
        
        // Check for common port fields
        $this->line("");
        $this->info("=== Port Field Analysis ===");
        $portFields = ['ifSpeed', 'ifOperStatus', 'ifAdminStatus', 'ifMtu', 'ifType'];
        foreach ($portFields as $field) {
            $count = RestApiMetricFieldMapping::where('librenms_table', 'ports')
                ->where('librenms_field', $field)
                ->where('enabled', true)
                ->count();
            $this->line("  {$field}: {$count} mappings");
        }
        
        // Show recent matches
        $this->line("");
        $this->info("=== Recent Matches (last 24 hours) ===");
        $recentMatches = RestApiMetricFieldMapping::where('last_matched_device_id', $deviceId)
            ->where('last_seen_at', '>', now()->subDay())
            ->orderBy('last_seen_at', 'desc')
            ->take(10)
            ->get();
            
        if ($recentMatches->count() > 0) {
            foreach ($recentMatches as $mapping) {
                $this->line("  {$mapping->api_field_name} -> {$mapping->librenms_table}.{$mapping->librenms_field}");
            }
        } else {
            $this->warn("  No recent matches found");
        }
        
        return 0;
    }
}
