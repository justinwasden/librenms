<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('rest_api_templates', function (Blueprint $table) {
            $table->string('resource_type', 50)->nullable()->after('vendor')->comment('The primary resource type this template deals with (e.g., device, storage).');
        });
    }


    public function down(): void
    {
        Schema::table('rest_api_templates', function (Blueprint $table) {
            $table->dropColumn('resource_type');
        });
    }
};