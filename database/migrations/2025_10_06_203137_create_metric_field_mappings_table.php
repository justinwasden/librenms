<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

				Schema::create('metric_field_mappings', function (Blueprint $table) {
				    $table->id();
				    $table->string('metric_name');
				    $table->string('librenms_table');
				    $table->string('librenms_field');
				    $table->string('resource_type')->nullable();
				    $table->string('vendor')->nullable();
				    $table->string('os')->nullable();
				    $table->boolean('enabled')->default(true);
				    $table->boolean('auto_learned')->default(false);
				    $table->timestamp('last_seen_at')->nullable();
				    $table->unsignedBigInteger('last_matched_device_id')->nullable();
				    $table->timestamps();
				});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metric_field_mappings');
    }
};

