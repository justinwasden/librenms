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
        Schema::table('device_api_endpoints', function (Blueprint $table) {
            $table->unsignedBigInteger('template_endpoint_id')->nullable()->after('device_id');
            $table->foreign('template_endpoint_id')
                ->references('id')
                ->on('device_api_template_endpoints')
                ->onDelete('cascade');
            $table->index('template_endpoint_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_api_endpoints', function (Blueprint $table) {
            $table->dropForeign(['template_endpoint_id']);
            $table->dropColumn('template_endpoint_id');
        });
    }
};
