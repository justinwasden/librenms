<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to add resource fields to rest_api_endpoints table
     */
    public function up(): void
    {
        Schema::table('rest_api_endpoints', function (Blueprint $table) {
            if (!Schema::hasColumn('rest_api_endpoints', 'resource_type')) {
                $table->string('resource_type', 50)->nullable()->after('metric_map');
            }
            if (!Schema::hasColumn('rest_api_endpoints', 'resource_id_path')) {
                $table->string('resource_id_path')->nullable()->after('resource_type');
            }
            if (!Schema::hasColumn('rest_api_endpoints', 'resource_name_path')) {
                $table->string('resource_name_path')->nullable()->after('resource_id_path');
            }
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::table('rest_api_endpoints', function (Blueprint $table) {
            $table->dropColumn(['resource_type', 'resource_id_path', 'resource_name_path']);
        });
    }
};
