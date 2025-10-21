<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make template_id nullable in rest_api_endpoints
     */
    public function up(): void
    {
        if (Schema::hasTable('rest_api_endpoints')) {
            Schema::table('rest_api_endpoints', function (Blueprint $table) {
                $table->unsignedBigInteger('template_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        if (Schema::hasTable('rest_api_endpoints')) {
            Schema::table('rest_api_endpoints', function (Blueprint $table) {
                $table->unsignedBigInteger('template_id')->nullable(false)->change();
            });
        }
    }
};
