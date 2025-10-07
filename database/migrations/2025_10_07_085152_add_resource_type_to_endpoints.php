<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('rest_api_endpoints', function (Blueprint $table) {
            // Add the resource_type column after the 'method' column
            $table->string('resource_type', 50)->nullable()->after('method')->comment('The type of resource this endpoint primarily monitors (e.g., port, storage).');
        });
    }

    public function down(): void
    {
        Schema::table('rest_api_endpoints', function (Blueprint $table) {
            $table->dropColumn('resource_type');
        });
    }
};