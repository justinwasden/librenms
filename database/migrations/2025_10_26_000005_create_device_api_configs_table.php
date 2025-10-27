<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_api_configs', function (Blueprint $table) {
            // Ensure InnoDB for foreign keys
            $table->engine = 'InnoDB';

            $table->id();

            // Match LibreNMS devices.device_id type (int unsigned)
            $table->unsignedInteger('device_id');

            // Auth schema reference (if your api_auth_schemas.id is bigIncrements, this is correct)
            $table->foreignId('schema_id')->constrained('api_auth_schemas');

            $table->string('base_url');
            $table->boolean('verify_ssl')->default(true);
            $table->json('extra_headers')->nullable();
            $table->json('values'); // JSON map of field -> stored value (secrets encrypted)

            // Optional: track applied template (ensure type matches device_api_templates.id)
            $table->foreignId('template_id')->nullable()->constrained('device_api_templates');

            $table->timestamps();

            // FK to devices(device_id) with matching type
            $table->foreign('device_id')
                ->references('device_id')
                ->on('devices')
                ->onDelete('cascade');

            // If you want one config per device, add a unique index:
            // $table->unique('device_id');

            // If you want one config per device per schema, use:
            // $table->unique(['device_id', 'schema_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_api_configs');
    }
};