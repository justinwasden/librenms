<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations - Remove old custom storage tables
     */
    public function up(): void
    {
        // Drop old custom tables in correct order (foreign keys first)
        Schema::dropIfExists('storage_array_hosts');
        Schema::dropIfExists('storage_array_volumes');
        Schema::dropIfExists('storage_controllers');
        Schema::dropIfExists('storage_arrays');
        
        // Clean up any old REST API data from native tables
        DB::table('storage')
            ->whereIn('device_id', function($query) {
                $query->select('device_id')
                      ->from('devices')
                      ->where('os', 'purestorage');
            })
            ->where('storage_type', 'rest-api')
            ->delete();
        
        DB::table('ports')
            ->whereIn('device_id', function($query) {
                $query->select('device_id')
                      ->from('devices')
                      ->where('os', 'purestorage');
            })
            ->where('port_descr_type', 'rest-api')
            ->delete();
        
        DB::table('sensors')
            ->whereIn('device_id', function($query) {
                $query->select('device_id')
                      ->from('devices')
                      ->where('os', 'purestorage');
            })
            ->where('sensor_type', 'rest-api')
            ->delete();
        
        // Clear fallback metrics table
        DB::table('rest_api_metrics')->truncate();
        
        // Remove old/incorrect mappings
        DB::table('metric_field_mappings')
            ->where(function($query) {
                $query->where('os', 'purestorage')
                      ->orWhere('vendor', 'Pure Storage');
            })
            ->delete();
    }

    /**
     * Reverse the migrations - Recreate the old tables if needed
     */
    public function down(): void
    {
        // Recreate storage_arrays table
        Schema::create('storage_arrays', function (Blueprint $table) {
            $table->id('array_id');
            $table->unsignedInteger('device_id');
            $table->string('name');
            $table->string('array_type')->default('rest-api');
            $table->string('model')->nullable();
            $table->string('version')->nullable();
            $table->string('serial_number')->nullable();
            $table->bigInteger('total_capacity')->default(0);
            $table->bigInteger('total_physical')->default(0);
            $table->bigInteger('total_used')->default(0);
            $table->bigInteger('total_free')->default(0);
            $table->bigInteger('total_provisioned')->default(0);
            $table->bigInteger('snapshots')->default(0);
            $table->bigInteger('system')->default(0);
            $table->decimal('data_reduction', 10, 2)->default(0);
            $table->decimal('total_reduction', 10, 2)->default(0);
            $table->decimal('thin_provisioning', 10, 2)->default(0);
            $table->decimal('shared_space', 10, 2)->nullable();
            $table->decimal('deduplication', 10, 2)->nullable();
            $table->decimal('compression', 10, 2)->nullable();
            $table->string('status')->default('unknown');
            $table->string('health')->nullable();
            $table->bigInteger('read_bandwidth')->nullable();
            $table->bigInteger('write_bandwidth')->nullable();
            $table->integer('read_iops')->nullable();
            $table->integer('write_iops')->nullable();
            $table->decimal('read_latency', 10, 3)->nullable();
            $table->decimal('write_latency', 10, 3)->nullable();
            $table->timestamp('last_polled')->nullable();
            $table->timestamps();
            
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->unique(['device_id', 'name']);
        });
        
        // Recreate other tables if needed
        Schema::create('storage_controllers', function (Blueprint $table) {
            $table->id('controller_id');
            $table->unsignedInteger('device_id');
            $table->string('name');
            $table->string('model')->nullable();
            $table->string('status')->default('unknown');
            $table->string('mode')->nullable();
            $table->string('version')->nullable();
            $table->timestamp('last_polled')->nullable();
            $table->timestamps();
            
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->unique(['device_id', 'name']);
        });
        
        Schema::create('storage_array_volumes', function (Blueprint $table) {
            $table->id('volume_id');
            $table->unsignedInteger('device_id');
            $table->string('name');
            $table->string('serial')->nullable();
            $table->bigInteger('provisioned')->default(0);
            $table->bigInteger('total_physical')->default(0);
            $table->bigInteger('total_used')->default(0);
            $table->decimal('data_reduction', 10, 2)->default(0);
            $table->decimal('thin_provisioning', 10, 2)->default(0);
            $table->json('mapped_hosts')->nullable();
            $table->string('status')->default('unknown');
            $table->timestamp('last_polled')->nullable();
            $table->timestamps();
            
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->unique(['device_id', 'name']);
        });
        
        Schema::create('storage_array_hosts', function (Blueprint $table) {
            $table->id('host_id');
            $table->unsignedInteger('device_id');
            $table->string('name');
            $table->string('personality')->nullable();
            $table->json('iqns')->nullable();
            $table->json('wwns')->nullable();
            $table->json('nqns')->nullable();
            $table->json('port_connectivity_details')->nullable();
            $table->json('connected_ports')->nullable();
            $table->json('mapped_volumes')->nullable();
            $table->integer('connection_count')->default(0);
            $table->string('status')->default('unknown');
            $table->timestamp('last_polled')->nullable();
            $table->timestamps();
            
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->unique(['device_id', 'name']);
        });
    }
};
