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
        // Storage array hosts (connected hosts/initiators)
        Schema::create('storage_array_hosts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('host_name', 255);
            $table->string('host_type', 64)->nullable();
            $table->string('iqn', 255)->nullable();
            $table->json('wwns')->nullable();
            $table->string('os_type', 64)->nullable();
            $table->boolean('is_local')->default(true);
            $table->timestamps();

            $table->unique(['device_id', 'host_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });

        // Storage array drives
        Schema::create('storage_array_drives', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('drive_name', 255);
            $table->string('drive_type', 64)->nullable();
            $table->string('drive_status', 64)->nullable();
            $table->bigInteger('capacity_bytes')->nullable();
            $table->string('protocol', 32)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('serial', 128)->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'drive_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });

        // Storage array host groups
        Schema::create('storage_array_host_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('group_name', 255);
            $table->integer('host_count')->default(0);
            $table->timestamps();

            $table->unique(['device_id', 'group_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });

        // Storage array protection groups (replication/snapshots)
        Schema::create('storage_array_protection_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('group_name', 255);
            $table->string('source', 255)->nullable();
            $table->string('target', 255)->nullable();
            $table->string('status', 64)->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'group_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });

        // Storage array FC/iSCSI ports
        Schema::create('storage_array_fc_ports', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('port_name', 255);
            $table->string('wwn', 64)->nullable();
            $table->string('port_type', 32)->nullable();  // fc, iscsi, eth
            $table->string('port_status', 32)->nullable();
            $table->bigInteger('speed_bps')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'port_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });

        // Storage array connections (host to volume mappings)
        Schema::create('storage_array_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('host_name', 255);
            $table->string('volume_name', 255);
            $table->integer('lun')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'host_name', 'volume_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('storage_array_connections');
        Schema::dropIfExists('storage_array_fc_ports');
        Schema::dropIfExists('storage_array_protection_groups');
        Schema::dropIfExists('storage_array_host_groups');
        Schema::dropIfExists('storage_array_drives');
        Schema::dropIfExists('storage_array_hosts');
    }
};
