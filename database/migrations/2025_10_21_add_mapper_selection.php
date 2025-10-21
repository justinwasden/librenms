<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add mapper selection fields to rest_api_device_templates table
        Schema::table('rest_api_device_templates', function (Blueprint $table) {
            // Selected vendor mapper name
            $table->string('mapper_name')->nullable()->comment('Selected vendor mapper (Pure Storage, Cisco, etc)');
            
            // Custom mappings stored as JSON
            $table->longText('custom_mappings')->nullable()->comment('User-defined custom mappings as JSON');
            
            // Name of custom mapping set
            $table->string('custom_mapping_name')->nullable()->comment('Name of custom mapping configuration');
            
            // Mapper selection source (user_selected, auto_detected, custom_device, fallback)
            $table->string('mapper_source')->default('fallback')->comment('How mapper was selected');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('rest_api_device_templates', function (Blueprint $table) {
            $table->dropColumn(['mapper_name', 'custom_mappings', 'custom_mapping_name', 'mapper_source']);
        });
    }
};
