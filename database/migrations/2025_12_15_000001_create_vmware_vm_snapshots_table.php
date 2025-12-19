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
        Schema::create('vmware_vm_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('device_id')->index();
            $table->string('vm_name', 255)->index();
            $table->string('vm_moref', 64)->nullable();
            $table->enum('power_state', ['poweredOn', 'poweredOff', 'suspended', 'unknown'])->default('unknown');
            $table->unsignedInteger('snapshot_count')->default(0);
            $table->text('snapshot_details')->nullable()->comment('JSON array of snapshot details');
            $table->timestamp('oldest_snapshot_date')->nullable();
            $table->unsignedInteger('total_snapshot_size_gb')->nullable()->comment('Total size of all snapshots in GB');
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('device_id')
                ->references('device_id')
                ->on('devices')
                ->onDelete('cascade');

            // Unique constraint to prevent duplicates
            $table->unique(['device_id', 'vm_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vmware_vm_snapshots');
    }
};
