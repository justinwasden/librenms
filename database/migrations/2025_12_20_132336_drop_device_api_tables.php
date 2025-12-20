<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drop all device_api_* tables after migration to device attributes.
     * Tables are dropped in reverse order of creation to respect foreign key constraints.
     */
    public function up(): void
    {
        // Drop tables in reverse order of creation (foreign keys first)
        Schema::dropIfExists('device_api_endpoints');
        Schema::dropIfExists('device_api_template_endpoints');
        Schema::dropIfExists('device_api_configs');
        Schema::dropIfExists('device_api_templates');
        Schema::dropIfExists('device_api_auth_schema_fields');
        Schema::dropIfExists('device_api_auth_schemas');
    }

    /**
     * Reverse the migrations.
     *
     * Cannot rollback - data has been migrated to device attributes.
     * Restore from backup if needed.
     */
    public function down(): void
    {
        throw new \Exception('Cannot rollback table drops - data is now in device attributes. Restore from database backup if needed.');
    }
};
