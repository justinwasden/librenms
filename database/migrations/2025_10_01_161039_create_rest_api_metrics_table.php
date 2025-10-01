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
        Schema::create('rest_api_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('rest_api_endpoints')->onDelete('cascade');
            $table->string('metric_name');
            $table->text('metric_value');
            $table->timestamp('collected_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rest_api_metrics');
    }
};