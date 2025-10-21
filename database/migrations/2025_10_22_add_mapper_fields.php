<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add mapper selection fields to rest_api_device_templates
     * These fields are needed for the vendor-agnostic mapper system
     */
    public function up(): void
    {
        if (!Schema::hasTable('rest_api_device_templates')) {
            return;
        }

        Schema::table('rest_api_device_templates', function (Blueprint $table) {
            // Only add if they don't already exist
            $columns = \DB::connection()->getSchemaBuilder()->getColumnListing('rest_api_device_templates');
            
            if (!in_array('mapper_name', $columns)) {
                $table->string('mapper_name')->nullable()->after('template_id');
            }
            if (!in_array('custom_mappings', $columns)) {
                $table->longText('custom_mappings')->nullable()->after('mapper_name');
            }
            if (!in_array('custom_mapping_name', $columns)) {
                $table->string('custom_mapping_name')->nullable()->after('custom_mappings');
            }
            if (!in_array('mapper_source', $columns)) {
                $table->string('mapper_source')->default('fallback')->after('custom_mapping_name');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('rest_api_device_templates')) {
            Schema::table('rest_api_device_templates', function (Blueprint $table) {
                $columns = \DB::connection()->getSchemaBuilder()->getColumnListing('rest_api_device_templates');
                
                $dropColumns = [];
                if (in_array('mapper_name', $columns)) $dropColumns[] = 'mapper_name';
                if (in_array('custom_mappings', $columns)) $dropColumns[] = 'custom_mappings';
                if (in_array('custom_mapping_name', $columns)) $dropColumns[] = 'custom_mapping_name';
                if (in_array('mapper_source', $columns)) $dropColumns[] = 'mapper_source';
                
                if (!empty($dropColumns)) {
                    $table->dropColumn($dropColumns);
                }
            });
        }
    }
};
