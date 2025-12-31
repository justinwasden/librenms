<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add discovered_via column to track data source (snmp, api, or both)
     * This prevents SNMP discovery from deleting API-discovered data and vice versa.
     */
    public function up(): void
    {
        $tables = ['ports', 'vlans', 'sensors', 'storage', 'mempools', 'vminfo', 'processors'];
        
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'discovered_via')) {
                Schema::table($table, function (Blueprint $table) {
                    // enum: 'snmp', 'api', 'both' - defaults to 'snmp' for backward compatibility
                    $table->string('discovered_via', 10)->default('snmp')->after('device_id');
                    $table->index('discovered_via');
                });
            }
        }
    }

    public function down(): void
    {
        $tables = ['ports', 'vlans', 'sensors', 'storage', 'mempools', 'vminfo', 'processors'];
        
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'discovered_via')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropIndex([$table->getTable() . '_discovered_via_index']);
                    $table->dropColumn('discovered_via');
                });
            }
        }
    }
};
