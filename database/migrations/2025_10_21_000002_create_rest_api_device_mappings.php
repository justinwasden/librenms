<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rest_api_device_mappings')) {
            Schema::create('rest_api_device_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('device_id')->constrained('devices')->onDelete('cascade');
                $table->foreignId('endpoint_id')->constrained('rest_api_endpoints')->onDelete('cascade');
                $table->enum('mapping_type', ['vendor', 'custom'])->default('vendor');
                $table->string('mapping_name');
                $table->timestamps();

                $table->unique(['device_id', 'endpoint_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rest_api_device_mappings');
    }
};
