<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table for hypervisor clusters/datacenters
        Schema::create('hypervisor_clusters', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('cluster_type', 32)->comment('vmware, proxmox, hyperv, etc.');
            $table->string('cluster_id', 128)->comment('Unique ID from hypervisor API');
            $table->string('cluster_name', 255);
            $table->string('parent_id', 128)->nullable()->comment('For nested structures (e.g., datacenter contains clusters)');
            $table->string('parent_name', 255)->nullable();
            $table->enum('cluster_level', ['datacenter', 'cluster', 'resource_pool'])->default('cluster');
            $table->json('metadata')->nullable()->comment('Additional hypervisor-specific data');
            $table->timestamps();
            
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->unique(['device_id', 'cluster_type', 'cluster_id']);
        });

        // Table for hypervisor hosts (ESXi hosts, Proxmox nodes, Hyper-V hosts)
        Schema::create('hypervisor_hosts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index()->comment('The vCenter/cluster manager device');
            $table->unsignedInteger('host_device_id')->nullable()->comment('LibreNMS device_id if host is monitored separately');
            $table->string('host_type', 32)->comment('esxi, proxmox-node, hyperv, etc.');
            $table->string('host_id', 128)->comment('Unique ID from hypervisor API');
            $table->string('host_name', 255);
            $table->string('cluster_id', 128)->nullable()->comment('Parent cluster ID');
            $table->string('role', 64)->nullable()->comment('master, node, standalone, etc.');
            $table->string('status', 32)->nullable()->comment('connected, disconnected, maintenance, etc.');
            $table->string('version', 64)->nullable();
            $table->unsignedBigInteger('cpu_cores')->nullable();
            $table->unsignedBigInteger('cpu_threads')->nullable();
            $table->unsignedBigInteger('memory_total')->nullable()->comment('Total memory in bytes');
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable()->comment('Additional hypervisor-specific data');
            $table->timestamps();
            
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->foreign('host_device_id')->references('device_id')->on('devices')->onDelete('set null');
            $table->unique(['device_id', 'host_type', 'host_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hypervisor_hosts');
        Schema::dropIfExists('hypervisor_clusters');
    }
};
