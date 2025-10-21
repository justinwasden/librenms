<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rest_api_device_templates', function (Blueprint $table) {
            $table->string('mapper_name')->nullable()->after('template_id')->comment('Selected vendor mapper (Pure Storage, Cisco, etc)');
            $table->longText('custom_mappings')->nullable()->after('mapper_name')->comment('User-defined custom mappings as JSON');
            $table->string('custom_mapping_name')->nullable()->after('custom_mappings')->comment('Name of custom mapping configuration');
            $table->string('mapper_source')->default('fallback')->after('custom_mapping_name')->comment('How mapper was selected: user_selected, auto_detected, custom_device, fallback');
        });
    }

    public function down(): void
    {
        Schema::table('rest_api_device_templates', function (Blueprint $table) {
            $table->dropColumn(['mapper_name', 'custom_mappings', 'custom_mapping_name', 'mapper_source']);
        });
    }
};
