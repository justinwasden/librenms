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
        if (Schema::hasTable('rest_api_connections')) {
            Schema::table('rest_api_connections', function (Blueprint $table) {
                // Port should be unsigned small integer, nullable
                $table->unsignedInteger('port')->nullable()->after('base_url');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('rest_api_connections')) {
            Schema::table('rest_api_connections', function (Blueprint $table) {
                $table->dropColumn('port');
            });
        }
    }
};