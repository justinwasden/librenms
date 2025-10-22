<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\Sensor as SensorEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Map invalid sensor classes to valid ones
        $mapping = [
            'gauge' => 'count',
            'counter' => 'count',
            'ratio' => 'percent',
            'unknown' => 'count',
            'generic' => 'count',
        ];

        foreach ($mapping as $invalid => $valid) {
            DB::table('sensors')
                ->where('sensor_class', $invalid)
                ->update(['sensor_class' => $valid]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse operation - we're fixing bad data
    }
};
