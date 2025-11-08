<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storage_controllers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('device_id');

            // Controller identity
            $table->string('controller_name', 128)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('mode', 64)->nullable(); // e.g., 'primary', 'secondary', 'active', 'standby'
            $table->string('version', 128)->nullable();

            $table->timestamps();

            $table->index(['device_id', 'controller_name']);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_controllers');
    }
};
