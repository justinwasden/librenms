<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIterationToApiEndpointsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('device_api_template_endpoints', function (Blueprint $table) {
            $table->string('for_each')->nullable()->after('display_order');
            $table->json('for_each_options')->nullable()->after('for_each');
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
            $table->dropColumn(['for_each', 'for_each_options']);
        });
    }
}
