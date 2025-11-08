<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_arrays', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('device_id');

            // Identity
            $table->string('vendor', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('serial', 128)->nullable();
            $table->string('array_name', 128)->nullable();
            $table->string('software_version', 128)->nullable();

            // Capacity roll-up
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedBigInteger('free_bytes')->default(0);
            $table->decimal('used_pct', 6, 2)->default(0);
            $table->decimal('data_reduction_ratio', 8, 4)->nullable();

            // Counts
            $table->unsignedInteger('controllers_count')->default(0);
            $table->unsignedInteger('volumes_count')->default(0);
            $table->unsignedInteger('hosts_count')->default(0);
            $table->unsignedInteger('replication_links_count')->default(0);

            // Ops
            $table->unsignedInteger('alerts_open_count')->default(0);
            $table->timestamp('last_polled_at')->nullable();

            $table->timestamps();

            $table->unique(['device_id']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_arrays');
    }
};
