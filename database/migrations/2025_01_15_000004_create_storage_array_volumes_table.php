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
        Schema::create('storage_array_volumes', function (Blueprint $table) {
            $table->id('volume_id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedBigInteger('array_id')->nullable()->index();
            
            // Identity
            $table->string('name')->index(); // Volume name
            $table->string('volume_type')->default('rest-api'); // rest-api, lun, vvol, etc.
            $table->string('serial')->nullable()->index(); // Volume serial number
            $table->string('wwn')->nullable(); // World Wide Name
            
            // Pod/Protection Group
            $table->string('pod_name')->nullable(); // ActiveCluster pod name
            $table->string('pod_id')->nullable();
            $table->string('volume_group')->nullable(); // Volume group/protection group name
            $table->string('volume_group_id')->nullable();
            
            // Capacity metrics (in bytes)
            $table->bigInteger('total_provisioned')->default(0); // Provisioned/allocated size
            $table->bigInteger('used_provisioned')->default(0); // Actually used space
            $table->bigInteger('total_physical')->default(0); // Physical space on array
            $table->bigInteger('total_used')->default(0); // Total used (including snapshots)
            $table->bigInteger('snapshots')->default(0); // Space used by snapshots
            $table->bigInteger('unique')->default(0); // Unique data (non-shared)
            $table->bigInteger('shared')->default(0); // Shared data
            $table->bigInteger('system')->default(0); // System overhead
            
            // Efficiency metrics
            $table->decimal('data_reduction', 10, 2)->default(0); // Data reduction ratio
            $table->decimal('total_reduction', 10, 2)->default(0); // Total reduction ratio
            $table->decimal('data_reduction_percent', 10, 2)->default(0); // Reduction as percentage
            $table->decimal('thin_provisioning', 10, 2)->default(0); // Thin provisioning ratio
            
            // Status
            $table->string('status')->default('unknown'); // ok, warning, critical, unknown
            $table->string('provisioned')->nullable(); // thinly_provisioned, thickly_provisioned
            
            // Host mapping
            $table->integer('host_count')->default(0); // Number of hosts mapped to this volume
            $table->json('mapped_hosts')->nullable(); // Array of host names mapped to this volume
            
            // QoS/Performance
            $table->bigInteger('qos_max_iops')->nullable();
            $table->bigInteger('qos_max_bandwidth')->nullable(); // bytes/sec
            
            // Performance metrics (optional - can be in RRD)
            $table->bigInteger('read_bandwidth')->nullable(); // bytes/sec
            $table->bigInteger('write_bandwidth')->nullable(); // bytes/sec
            $table->integer('read_iops')->nullable();
            $table->integer('write_iops')->nullable();
            $table->decimal('read_latency', 10, 3)->nullable(); // milliseconds
            $table->decimal('write_latency', 10, 3)->nullable(); // milliseconds
            
            // Timestamps
            $table->timestamp('created_at_source')->nullable(); // When volume was created on array
            $table->timestamp('last_polled')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('device_id')
                  ->references('device_id')
                  ->on('devices')
                  ->onDelete('cascade');
                  
            $table->foreign('array_id')
                  ->references('array_id')
                  ->on('storage_arrays')
                  ->onDelete('cascade');
                  
            // Unique constraint - serial is unique if present, otherwise fall back to name
            $table->unique(['device_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_array_volumes');
    }
};
