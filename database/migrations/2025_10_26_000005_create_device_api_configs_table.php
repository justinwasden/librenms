<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDeviceApiConfigsTable extends Migration
{
    public function up(): void
    {
        Schema::create('device_api_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');

            $table->foreignId('schema_id')->constrained('api_auth_schemas');
            $table->foreignId('template_id')->nullable()->constrained('device_api_templates');

            $table->string('base_url');
            $table->boolean('verify_ssl')->default(true);
            $table->json('extra_headers')->nullable();

            // store per-field values in JSON; encrypt secrets before save
            $table->json('values'); // { field_name: stored_value_or_ciphertext }

            $table->timestamps();

            $table->unique(['device_id', 'schema_id']); // adjust if you want multiple connections per schema
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_api_configs');
    }
}