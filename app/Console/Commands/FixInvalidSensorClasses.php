<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\Sensor as SensorEnum;

class FixInvalidSensorClasses extends Command
{
    protected $signature = 'sensors:fix-invalid-classes';

    protected $description = 'Fix sensors with invalid sensor_class values by converting them to valid enum values';

    public function handle()
    {
        $this->line('Checking for sensors with invalid sensor_class values...');

        // Map invalid sensor classes to valid ones
        $mapping = [
            'gauge' => 'count',
            'counter' => 'count',
            'ratio' => 'percent',
            'unknown' => 'count',
            'generic' => 'count',
        ];

        $totalUpdated = 0;

        foreach ($mapping as $invalid => $valid) {
            $count = DB::table('sensors')
                ->where('sensor_class', $invalid)
                ->count();

            if ($count > 0) {
                $this->info("Found {$count} sensors with class '{$invalid}'");
                
                DB::table('sensors')
                    ->where('sensor_class', $invalid)
                    ->update(['sensor_class' => $valid]);

                $this->line("  Updated to '{$valid}'");
                $totalUpdated += $count;
            }
        }

        if ($totalUpdated === 0) {
            $this->info('No invalid sensor classes found.');
        } else {
            $this->info("Successfully fixed {$totalUpdated} sensors!");
        }

        return 0;
    }
}
