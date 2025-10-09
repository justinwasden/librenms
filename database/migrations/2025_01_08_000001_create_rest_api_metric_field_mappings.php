<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // REST API Metric Field Mappings table
        if (!Schema::hasTable('rest_api_metric_field_mappings')) {
            Schema::create('rest_api_metric_field_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('device_id')->nullable(); // null = global mapping
                $table->string('api_field_name');
                $table->string('librenms_table');
                $table->string('librenms_field');
                $table->string('unit')->nullable();
                $table->string('transform')->nullable();
                $table->decimal('confidence_score', 5, 2)->default(0);
                $table->boolean('enabled')->default(true);
                $table->boolean('user_created')->default(false);
                $table->unsignedInteger('last_matched_device_id')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                
                $table->index(['api_field_name', 'librenms_table'], 'rest_api_map_field_table_idx');
                $table->index(['device_id', 'enabled'], 'rest_api_map_dev_enabled_idx');
                $table->index('confidence_score', 'rest_api_map_confidence_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('rest_api_metric_field_mappings');
    }
};
