<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBodyToDeviceApiTemplateEndpointsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('device_api_template_endpoints', function (Blueprint $table) {
            $table->json('body')->nullable()->after('headers');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('device_api_template_endpoints', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
}
