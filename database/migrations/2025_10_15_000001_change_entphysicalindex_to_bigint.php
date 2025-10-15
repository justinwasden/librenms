<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change entPhysicalIndex from INT to BIGINT UNSIGNED to support
     * larger hash values from REST API sources (Pure Storage, etc.)
     */
    public function up(): void
    {
        Schema::table('entPhysical', function (Blueprint $table) {
            $table->unsignedBigInteger('entPhysicalIndex')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entPhysical', function (Blueprint $table) {
            $table->integer('entPhysicalIndex')->change();
        });
    }
};
