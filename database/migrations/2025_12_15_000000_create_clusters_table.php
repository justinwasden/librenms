<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id')->index()->comment('LibreNMS device_id, if applicable');
            $table->string('cluster_name');
            $table->string('provider_type')->index()->comment('vmware_vcenter, hyperv, rdp, storage_array, server_cluster, network_cluster, application_cluster');
            $table->string('location')->nullable();
            $table->string('environment')->nullable(); // prod, staging, dev, dr
            $table->string('business_service')->nullable();
            $table->string('owner_team')->nullable();
            $table->string('software_version')->nullable();
            $table->string('api_version')->nullable();
            $table->string('config_hash')->nullable();
            $table->timestamp('last_config_change')->nullable();
            $table->string('state')->nullable(); // healthy, degraded, etc
            $table->boolean('maintenance_mode')->default(false);
            $table->timestamps();

            $table->index(['device_id', 'provider_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clusters');
    }
};
