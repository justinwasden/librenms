<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanupApiMetricsHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-metrics:cleanup-history 
                            {--days=30 : Number of days of history to retain}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old API metrics history data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $daysToRetain = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $cutoffDate = Carbon::now()->subDays($daysToRetain);
        
        $this->info("Cleaning up API metrics history older than {$cutoffDate->toDateTimeString()}");
        
        // Count records to be deleted
        $count = DB::table('device_api_metrics_history')
            ->where('collected_at', '<', $cutoffDate)
            ->count();
        
        if ($count === 0) {
            $this->info('No records to clean up.');
            return 0;
        }
        
        if ($dryRun) {
            $this->warn("[DRY RUN] Would delete {$count} historical metric records");
            
            // Show breakdown by device
            $breakdown = DB::table('device_api_metrics_history')
                ->select('device_id', DB::raw('count(*) as count'))
                ->where('collected_at', '<', $cutoffDate)
                ->groupBy('device_id')
                ->get();
            
            $this->table(
                ['Device ID', 'Records to Delete'],
                $breakdown->map(fn($row) => [$row->device_id, $row->count])
            );
            
            return 0;
        }
        
        // Delete old records
        $deleted = DB::table('device_api_metrics_history')
            ->where('collected_at', '<', $cutoffDate)
            ->delete();
        
        $this->info("Successfully deleted {$deleted} historical metric records");
        
        return 0;
    }
}
