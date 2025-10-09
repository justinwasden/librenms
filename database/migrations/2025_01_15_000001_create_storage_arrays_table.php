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
        Schema::create('storage_arrays', function (Blueprint $table) {
            $table->id('array_id');
            $table->unsignedInteger('device_id')->index();
            
            // Identity
            $table->string('name')->index();
            $table->string('array_type')->default('rest-api'); // pure-storage, netapp, etc.
            $table->string('model')->nullable();
            $table->string('version')->nullable();
            $table->string('serial_number')->nullable();
            
            // Capacity metrics (in bytes)
            $table->bigInteger('total_capacity')->default(0);
            $table->bigInteger('total_physical')->default(0);
            $table->bigInteger('total_used')->default(0);
            $table->bigInteger('total_free')->default(0);
            $table->bigInteger('total_provisioned')->default(0);
            $table->bigInteger('snapshots')->default(0);
            $table->bigInteger('system')->default(0); // System/overhead usage
            
            // Efficiency metrics
            $table->decimal('data_reduction', 10, 2)->default(0); // Ratio (e.g., 3.5:1)
            $table->decimal('total_reduction', 10, 2)->default(0); // Overall reduction ratio
            $table->decimal('thin_provisioning', 10, 2)->default(0); // Thin provisioning ratio
            $table->decimal('shared_space', 10, 2)->nullable(); // Shared space percentage
            $table->decimal('deduplication', 10, 2)->nullable(); // Deduplication ratio
            $table->decimal('compression', 10, 2)->nullable(); // Compression ratio
            
            // Status
            $table->string('status')->default('unknown'); // ok, warning, critical, unknown
            $table->string('health')->nullable();
            
            // Performance metrics (optional - can be moved to RRD)
            $table->bigInteger('read_bandwidth')->nullable(); // bytes/sec
            $table->bigInteger('write_bandwidth')->nullable(); // bytes/sec
            $table->integer('read_iops')->nullable();
            $table->integer('write_iops')->nullable();
            $table->decimal('read_latency', 10, 3)->nullable(); // milliseconds
            $table->decimal('write_latency', 10, 3)->nullable(); // milliseconds
            
            // Timestamps
            $table->timestamp('last_polled')->nullable();
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('device_id')
                  ->references('device_id')
                  ->on('devices')
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
        Schema::dropIfExists('storage_arrays');
    }
};
