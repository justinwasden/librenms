<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make rest_api_credentials.name column nullable
     * 
     * Fixes: SQLSTATE[HY000]: General error: 1364 Field 'name' doesn't have a default value
     */
    public function up(): void
    {
        Schema::table('rest_api_credentials', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        Schema::table('rest_api_credentials', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
