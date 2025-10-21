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
            return;
        }
        
        Schema::create('rest_api_connections', function (Blueprint $table) {
		        $table->id();
		        $table->integer('device_id')->unsigned();
		        $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');


		        $table->unsignedBigInteger('credential_id')->nullable();
		        $table->foreign('credential_id')->references('id')->on('rest_api_credentials')->onDelete('set null');

		        $table->string('name');
		        $table->string('base_url');
		        $table->integer('rate_limit')->default(60);
		        $table->boolean('enabled')->default(true);
		        $table->boolean('disable_ssl_verify')->default(false);
		        $table->timestamps();
		    });
		}
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign key checks temporarily
        \DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        try {
            // Drop dependent tables first
            Schema::dropIfExists('rest_api_endpoints');
            Schema::dropIfExists('rest_api_connections');
        } finally {
            // Re-enable foreign key checks
            \DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
};