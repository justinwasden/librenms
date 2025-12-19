<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_nodes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cluster_id')->index();
            $table->string('node_name');
            $table->string('role')->nullable(); // controller, worker, storage, etc.
            $table->boolean('effective')->default(true)->comment('Contributing capacity');
            $table->double('cpu_total_mhz')->nullable();
            $table->double('memory_total_mb')->nullable();
            $table->unsignedBigInteger('storage_total_bytes')->nullable();
            $table->double('network_bw_mbps')->nullable();
            $table->string('state')->nullable(); // up, down, degraded
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('cluster_id')->references('id')->on('clusters')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_nodes');
    }
};
