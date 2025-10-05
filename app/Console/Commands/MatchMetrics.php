<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Models\MetricFieldMapping;
use App\Services\DataMatcher;
use Illuminate\Support\Facades\DB;

class MatchMetrics extends Command
{
    protected $signature = 'metrics:match 
                           {--device_id= : Specific device ID to process}
                           {--vendor= : Filter devices by vendor}
                           {--os= : Filter devices by OS}
                           {--reset : Reset matched status and re-process all metrics}
                           {--dry-run : Show what would be matched without saving}
                           {--show-unmatched : Display unmatched metrics}';
    
    protected $description = 'Match REST API metrics to LibreNMS fields automatically';

    public function handle(): int
    {
        $this->info('🔍 Starting automatic metric matching...');
        $this->newLine();

        // Build device query
        $query = Device::query()
            ->whereHas('restApiConnections', fn($q) => $q->where('enabled', 1))
            ->when($this->option('device_id'), fn($q) => $q->where('device_id', $this->option('device_id')))
            ->when($this->option('vendor'), fn($q) => $q->where('vendor', $this->option('vendor')))
            ->when($this->option('os'), fn($q) => $q->where('os', $this->option('os')));

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->warn('No devices found with enabled REST API connections.');
            return Command::FAILURE;
        }

        $this->info("Found {$devices->count()} device(s) to process");
        $this->newLine();

        // Reset metrics if requested
        if ($this->option('reset')) {
            $this->warn('Resetting matched status for all metrics...');
            $matcher = new DataMatcher();
            $resetCount = 0;
            
            foreach ($devices as $device) {
                $resetCount += $matcher->resetMetrics($device);
            }
            
            $this->info("Reset {$resetCount} metrics for re-processing");
            $this->newLine();
        }

        // Process metrics
        $matcher = new DataMatcher();
        $totalMatched = 0;
        $totalUnmatched = 0;
        $totalErrors = 0;

        $progressBar = $this->output->createProgressBar($devices->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');

        foreach ($devices as $device) {
            $progressBar->setMessage("Processing {$device->hostname}");
            
            $stats = $matcher->processDeviceMetrics($device);
            
            $totalMatched += $stats['matched'];
            $totalUnmatched += $stats['unmatched'];
            $totalErrors += $stats['errors'];
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->info('📊 Matching Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['✅ Matched', $totalMatched],
                ['❌ Unmatched', $totalUnmatched],
                ['⚠️  Errors', $totalErrors],
            ]
        );

        // Show unmatched metrics if requested
        if ($this->option('show-unmatched') && $totalUnmatched > 0) {
            $this->newLine();
            $this->showUnmatchedMetrics();
        }

        $this->newLine();
        $this->info('✅ Metric matching complete!');

        return Command::SUCCESS;
    }

    protected function showUnmatchedMetrics(): void
    {
        $this->warn('📋 Unmatched Metrics (need manual mapping):');
        $this->newLine();

        $unmatchedMappings = MetricFieldMapping::unmatched()
            ->orderBy('vendor')
            ->orderBy('os')
            ->orderBy('metric_name')
            ->get();

        if ($unmatchedMappings->isEmpty()) {
            $this->info('No unmatched metrics found.');
            return;
        }

        $rows = [];
        foreach ($unmatchedMappings as $mapping) {
            $device = $mapping->lastMatchedDevice;
            
            $rows[] = [
                $mapping->metric_name,
                $mapping->resource_type ?? 'N/A',
                $mapping->vendor ?? 'generic',
                $mapping->os ?? 'generic',
                $device ? $device->hostname : 'N/A',
                $mapping->last_seen_at?->diffForHumans() ?? 'N/A',
            ];
        }

        $this->table(
            ['Metric Name', 'Resource Type', 'Vendor', 'OS', 'Last Device', 'Last Seen'],
            $rows
        );

        $this->newLine();
        $this->comment('💡 Tip: Configure these mappings in the admin panel at /admin/metric-field-mappings');
    }
}
