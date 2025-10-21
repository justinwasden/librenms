<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only update rest_api_templates if it exists (from 2025_01_08 migration)
        if (Schema::hasTable('rest_api_templates')) {
            Schema::table('rest_api_templates', function (Blueprint $table) {
                if (!Schema::hasColumn('rest_api_templates', 'template_data')) {
                    $table->json('template_data')->after('mappings')->nullable();
                }
            });
        }

        // Only update rest_api_endpoints if needed
        if (Schema::hasTable('rest_api_endpoints')) {
            Schema::table('rest_api_endpoints', function (Blueprint $table) {
                if (!Schema::hasColumn('rest_api_endpoints', 'template_id')) {
                    $table->unsignedBigInteger('template_id')->after('id')->nullable();
                    $table->foreign('template_id')->references('id')->on('rest_api_templates')->onDelete('cascade');
                }
                if (!Schema::hasColumn('rest_api_endpoints', 'http_method')) {
                    $table->string('http_method')->default('GET')->after('method');
                }
                if (!Schema::hasColumn('rest_api_endpoints', 'poll_interval')) {
                    $table->integer('poll_interval')->default(300)->after('http_method');
                }
                if (!Schema::hasColumn('rest_api_endpoints', 'resource_type')) {
                    $table->string('resource_type')->after('poll_interval');
                }
                if (!Schema::hasColumn('rest_api_endpoints', 'template_response_mapping')) {
                    $table->json('template_response_mapping')->after('resource_type')->nullable();
                }
            });
        }

        // Add mapper fields to rest_api_device_templates if it exists
        if (Schema::hasTable('rest_api_device_templates')) {
            Schema::table('rest_api_device_templates', function (Blueprint $table) {
                if (!Schema::hasColumn('rest_api_device_templates', 'mapper_name')) {
                    $table->string('mapper_name')->nullable()->after('template_id');
                }
                if (!Schema::hasColumn('rest_api_device_templates', 'custom_mappings')) {
                    $table->longText('custom_mappings')->nullable()->after('mapper_name');
                }
                if (!Schema::hasColumn('rest_api_device_templates', 'custom_mapping_name')) {
                    $table->string('custom_mapping_name')->nullable()->after('custom_mappings');
                }
                if (!Schema::hasColumn('rest_api_device_templates', 'mapper_source')) {
                    $table->string('mapper_source')->default('fallback')->after('custom_mapping_name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rest_api_device_templates')) {
            Schema::table('rest_api_device_templates', function (Blueprint $table) {
                $columns = \DB::connection()->getSchemaBuilder()->getColumnListing('rest_api_device_templates');
                if (in_array('mapper_name', $columns)) {
                    $table->dropColumn(['mapper_name', 'custom_mappings', 'custom_mapping_name', 'mapper_source']);
                }
            });
        }
    }
};
