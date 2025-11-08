<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_hosts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('device_id');

            // Host identity
            $table->string('host_name', 128)->nullable();
            $table->string('personality', 64)->nullable(); // e.g., 'vmware', 'linux', 'windows', 'aix'
            $table->string('host_group', 128)->nullable();
            $table->boolean('is_local')->default(false);

            // Connectivity
            $table->string('port_connectivity_status', 64)->nullable(); // e.g., 'connected', 'degraded', 'offline'
            $table->text('port_connectivity_details')->nullable(); // JSON or comma-separated port info

            // IQN/WWN identifiers
            $table->string('iqn', 255)->nullable();
            $table->text('wwns')->nullable(); // JSON array of WWNs

            $table->timestamps();

            $table->index(['device_id', 'host_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_hosts');
    }
};
