<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rest_api_metrics')) {
            Schema::create('rest_api_metrics', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('device_id');
                $table->string('endpoint_name')->nullable();
                $table->string('resource_type', 50)->index();
                $table->string('metric_key')->index();
                $table->text('metric_value')->nullable();
                $table->timestamp('last_updated')->nullable();
                $table->timestamps();

                $table->foreign('device_id')
                    ->references('device_id')
                    ->on('devices')
                    ->onDelete('cascade');

                $table->index(['device_id', 'resource_type']);
                $table->index(['device_id', 'metric_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rest_api_metrics');
    }
};
