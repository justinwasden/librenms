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
        Schema::create('storage_controllers', function (Blueprint $table) {
            $table->id('controller_id');
            $table->unsignedInteger('device_id')->index();
            $table->unsignedBigInteger('array_id')->nullable()->index();
            
            // Identity
            $table->string('name')->index(); // e.g., CT0, CT1, Controller-A
            $table->string('controller_type')->default('rest-api');
            $table->string('model')->nullable();
            $table->string('version')->nullable(); // Firmware/software version
            $table->string('serial_number')->nullable();
            
            // Status
            $table->string('status')->default('unknown'); // ok, warning, critical, degraded, unknown
            $table->string('mode')->nullable(); // primary, secondary, active, standby
            $table->string('health')->nullable();
            
            // Hardware info
            $table->string('hardware_version')->nullable();
            $table->string('bios_version')->nullable();
            
            // Timestamps
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
        Schema::dropIfExists('storage_controllers');
    }
};
