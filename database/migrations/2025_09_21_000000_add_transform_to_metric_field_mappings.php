<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table is named rest_api_metric_field_mappings, not metric_field_mappings
        if (!Schema::hasTable('rest_api_metric_field_mappings')) {
            return; // Table doesn't exist, skip this migration
        }
        
        if (!Schema::hasColumn('rest_api_metric_field_mappings', 'transform')) {
            Schema::table('rest_api_metric_field_mappings', function (Blueprint $table) {
                $table->string('transform', 50)->nullable()->after('unit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rest_api_metric_field_mappings') && Schema::hasColumn('rest_api_metric_field_mappings', 'transform')) {
            Schema::table('rest_api_metric_field_mappings', function (Blueprint $table) {
                $table->dropColumn('transform');
            });
        }
    }
};
