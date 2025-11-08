<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_volumes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('device_id');

            // Volume identity
            $table->string('volume_name', 128)->nullable();
            $table->string('volume_id', 128)->nullable(); // Some arrays use UUIDs

            // Performance metrics
            $table->unsignedBigInteger('read_bandwidth')->default(0); // bytes/sec
            $table->unsignedBigInteger('write_bandwidth')->default(0); // bytes/sec
            $table->unsignedBigInteger('read_iops')->default(0);
            $table->unsignedBigInteger('write_iops')->default(0);
            $table->decimal('read_latency', 10, 3)->nullable(); // microseconds
            $table->decimal('write_latency', 10, 3)->nullable(); // microseconds

            // Capacity
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);

            $table->timestamps();

            $table->index(['device_id', 'volume_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_volumes');
    }
};
