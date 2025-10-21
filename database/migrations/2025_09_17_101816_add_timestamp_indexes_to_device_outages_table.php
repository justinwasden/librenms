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
        Schema::table('device_outages', function (Blueprint $table) {
            // Check if indexes already exist
            $indexes = \DB::select("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME = 'device_outages' AND COLUMN_NAME IN ('going_down', 'up_again')");
            $indexNames = array_column($indexes, 'INDEX_NAME');
            
            if (!in_array('device_outages_going_down_index', $indexNames)) {
                $table->index('going_down');
            }
            if (!in_array('device_outages_up_again_index', $indexNames)) {
                $table->index('up_again');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_outages', function (Blueprint $table) {
            // Check if indexes exist before dropping
            $indexes = \DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME = 'device_outages' AND INDEX_NAME IN ('going_down', 'up_again')");
            
            if (count($indexes) > 0) {
                $table->dropIndex('going_down');
                $table->dropIndex('up_again');
            }
        });
    }
};
