<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDeviceApiTemplateEndpointsTable extends Migration
{
    public function up(): void
    {
        Schema::create('device_api_template_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('device_api_templates')->onDelete('cascade');
            $table->string('capability'); // sensors, ports, inventory, ipv4, etc.
            $table->string('method')->default('GET'); // GET/POST
            $table->string('path'); // relative path, e.g., "array", "array/performance"
            $table->json('request_body')->nullable();
            $table->json('headers')->nullable(); // per-endpoint overrides
            $table->integer('rate_limit_qps')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('transform')->nullable(); // key for normalizer, e.g., "normalizePureArraySensors"
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_api_template_endpoints');
    }
}