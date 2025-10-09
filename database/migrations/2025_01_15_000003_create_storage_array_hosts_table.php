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
        Schema::create('storage_array_hosts', function (Blueprint $table) {
            $table->id('host_id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedBigInteger('array_id')->nullable()->index();
            
            // Identity
            $table->string('name')->index(); // Host name as known to the array
            $table->string('host_type')->default('rest-api');
            
            // iSCSI/FC identifiers
            $table->json('iqns')->nullable(); // Array of IQNs for iSCSI
            $table->json('wwns')->nullable(); // Array of WWNs for Fibre Channel
            $table->json('nqns')->nullable(); // Array of NQNs for NVMe
            
            // Connectivity
            $table->string('port_connectivity_status')->default('unknown'); // connected, partially_connected, disconnected, unknown
            $table->json('port_connectivity_details')->nullable(); // Detailed connection info per port
            $table->integer('connection_count')->default(0); // Number of active connections/paths
            $table->json('connected_ports')->nullable(); // Array of connected port names
            
            // Host grouping
            $table->string('host_group')->nullable(); // Host group/cluster name
            $table->string('host_group_id')->nullable();
            
            // OS/Platform info
            $table->string('personality')->nullable(); // OS type hint (linux, windows, esxi, etc.)
            $table->string('preferred_arrays')->nullable(); // For multi-array setups
            
            // Volume associations
            $table->integer('volume_count')->default(0); // Number of volumes mapped to this host
            $table->json('mapped_volumes')->nullable(); // Array of volume names/IDs
            
            // Timestamps
            $table->timestamp('last_seen')->nullable(); // Last time host was seen connected
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
                  
            // Unique constraint
            $table->unique(['device_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_array_hosts');
    }
};
