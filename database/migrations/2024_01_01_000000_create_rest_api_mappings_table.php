<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRestApiMappingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Create mappings table
        Schema::create('rest_api_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('endpoint_id');
            $table->string('target_table');       // 'devices', 'storage', 'ports', 'sensors'
            $table->string('target_field');       // 'hostname', 'storage_descr', etc
            $table->string('source_field');       // JSONPath: '$.items[*].name'
            $table->string('data_type')->default('string'); // 'string', 'integer', 'float'
            $table->boolean('is_identifier')->default(false); // Unique ID for this resource?
            $table->boolean('is_required')->default(false);   // Must exist?
            $table->string('transformation')->nullable();     // 'divide:1024', 'multiply:8'
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('endpoint_id')->references('id')->on('rest_api_endpoints')->onDelete('cascade');
            $table->index(['endpoint_id', 'enabled']);
        });

        // Create mapping suggestions table (for preview analysis)
        Schema::create('rest_api_mapping_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('endpoint_id');
            $table->string('source_field');
            $table->string('suggested_target_table');
            $table->string('suggested_target_field');
            $table->float('confidence')->default(0.5); // 0-1
            $table->string('reason')->nullable();
            $table->text('sample_value')->nullable();
            $table->timestamps();

            $table->foreign('endpoint_id')->references('id')->on('rest_api_endpoints')->onDelete('cascade');
            $table->index(['endpoint_id', 'confidence']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('rest_api_mapping_suggestions');
        Schema::dropIfExists('rest_api_mappings');
    }
}
