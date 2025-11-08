<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove duplicate records, keeping only the most recently updated one per device
        $duplicates = \DB::table('device_api_configs')
            ->select('device_id')
            ->groupBy('device_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('device_id');

        foreach ($duplicates as $deviceId) {
            $configs = \DB::table('device_api_configs')
                ->where('device_id', $deviceId)
                ->orderBy('updated_at', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            // Keep the first one (most recently updated), delete the rest
            $keepId = $configs->first()->id;
            \DB::table('device_api_configs')
                ->where('device_id', $deviceId)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        // Add unique constraint on device_id
        Schema::table('device_api_configs', function (Blueprint $table) {
            $table->unique('device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_api_configs', function (Blueprint $table) {
            $table->dropUnique(['device_id']);
        });
    }
};
