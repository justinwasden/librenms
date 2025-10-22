<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Delete sensors created from rest-api with non-numeric sensor descriptions
        // These are likely metadata that was incorrectly stored as sensors
        
        $badPatterns = [
            'sensor_descr',
            'component_',
            'link_',
            'vlan_',
            'ipv4_',
            'remote_',
            'local_',
            'Transceiver_',
            'Wavelength',
            'Connector_',
            'Cable_',
            'Link_Length',
        ];

        foreach ($badPatterns as $pattern) {
            DB::table('sensors')
                ->where('sensor_type', 'rest-api')
                ->where('sensor_descr', 'like', '%' . $pattern . '%')
                ->delete();
        }

        // Delete any sensors with non-numeric sensor names that snuck in
        // (like hostnames, port names, etc.)
        DB::table('sensors')
            ->where('sensor_type', 'rest-api')
            ->where(function ($query) {
                // Only delete if sensor_current is not numeric
                $query->whereNull('sensor_current')
                      ->orWhere('sensor_current', '');
            })
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse operation - we're cleaning up bad data
    }
};
