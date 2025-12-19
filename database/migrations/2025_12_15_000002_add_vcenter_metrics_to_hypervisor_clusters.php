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
        Schema::table('hypervisor_clusters', function (Blueprint $table) {
            // Add vCenter cluster metrics
            $table->unsignedInteger('num_hosts')->nullable()->after('metadata')->comment('Number of hosts in cluster');
            $table->unsignedInteger('num_effective_hosts')->nullable()->comment('Number of effective hosts');
            $table->unsignedInteger('num_vms_total')->nullable()->comment('Total number of VMs');
            $table->unsignedInteger('num_vms_powered_on')->nullable()->comment('Number of powered on VMs');
            $table->unsignedBigInteger('total_cpu_mhz')->nullable()->comment('Total CPU in MHz');
            $table->unsignedBigInteger('effective_cpu_mhz')->nullable()->comment('Available CPU in MHz');
            $table->decimal('cpu_usage_pct', 5, 2)->nullable()->comment('CPU usage percentage');
            $table->decimal('total_memory_mb', 12, 2)->nullable()->comment('Total memory in MB');
            $table->decimal('effective_memory_mb', 12, 2)->nullable()->comment('Available memory in MB');
            $table->decimal('memory_usage_pct', 5, 2)->nullable()->comment('Memory usage percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hypervisor_clusters', function (Blueprint $table) {
            $table->dropColumn([
                'num_hosts',
                'num_effective_hosts',
                'num_vms_total',
                'num_vms_powered_on',
                'total_cpu_mhz',
                'effective_cpu_mhz',
                'cpu_usage_pct',
                'total_memory_mb',
                'effective_memory_mb',
                'memory_usage_pct',
            ]);
        });
    }
};
