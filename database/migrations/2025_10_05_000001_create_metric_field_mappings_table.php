<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_field_mappings', function (Blueprint $table) {
            $table->id();

            // Metric identification
            $table->string('metric_name', 100)->index();
            $table->string('resource_type', 50)->index()->nullable();

            // Platform context (optional - for vendor/OS specific mappings)
            $table->string('vendor', 100)->nullable()->index();
            $table->string('os', 100)->nullable()->index();

            // LibreNMS mapping target
            $table->string('librenms_table', 100)->index();
            $table->string('librenms_field', 100)->index();

            // Data type and transformation hints
            $table->string('data_type', 20)->default('numeric'); // numeric, string, boolean, json
            $table->string('unit', 50)->nullable(); // bytes, celsius, percent, etc.
            $table->decimal('multiplier', 10, 4)->default(1.0000); // For unit conversions

            // Matching tracking
            $table->unsignedInteger('last_matched_device_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('auto_learned')->default(true);
            $table->boolean('enabled')->default(true);

            // Optional description
            $table->text('description')->nullable();

            $table->timestamps();

            // Composite unique index - metric + resource + vendor + OS must be unique
            $table->unique(
                ['metric_name', 'resource_type', 'vendor', 'os'], 
                'unique_metric_mapping'
            );

            // Index for quick lookups
            $table->index(['metric_name', 'resource_type'], 'metric_lookup');
            $table->index(['librenms_table', 'librenms_field'], 'target_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_field_mappings');
    }
};
