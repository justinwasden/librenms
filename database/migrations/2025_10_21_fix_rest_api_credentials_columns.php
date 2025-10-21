<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make rest_api_credentials columns nullable
     * 
     * Fixes:
     * - SQLSTATE[HY000]: Field 'name' doesn't have a default value
     * - SQLSTATE[HY000]: Field 'authentication_type_id' doesn't have a default value
     * 
     * Both columns need to be nullable to allow credentials to be created
     * without immediately providing all values.
     */
    public function up(): void
    {
        Schema::table('rest_api_credentials', function (Blueprint $table) {
            // Make both columns nullable
            $table->string('name')->nullable()->change();
            $table->unsignedBigInteger('authentication_type_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        Schema::table('rest_api_credentials', function (Blueprint $table) {
            // Revert both columns back to NOT NULL
            $table->string('name')->nullable(false)->change();
            $table->unsignedBigInteger('authentication_type_id')->nullable(false)->change();
        });
    }
};
