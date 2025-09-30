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
        Schema::create('rest_api_connections', function (Blueprint $table) {
            $table->id();
            $table->integer('device_id')->unsigned();
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->foreignId('credential_id')->nullable()->constrained('rest_api_credentials')->onDelete('set null');
            $table->string('name');
            $table->string('base_url');
            $table->integer('rate_limit')->default(60);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rest_api_connections');
    }
};