<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDeviceApiTemplatesTable extends Migration
{
    public function up(): void
    {
        Schema::create('device_api_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., pure_flasharray_default
            $table->string('label');
            $table->json('os_keys'); // ["purestorage.flasharray"], ["proxmox.ve"]
            $table->foreignId('schema_id')->constrained('api_auth_schemas');
            $table->json('default_values')->nullable(); // field defaults (non-secret)
            $table->json('modules')->nullable(); // ["sensors","inventory","ports","ipv4"]
            $table->json('capabilities')->nullable(); // optional
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_api_templates');
    }
}
