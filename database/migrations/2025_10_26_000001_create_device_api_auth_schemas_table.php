<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApiAuthSchemasTable extends Migration
{
    public function up(): void
    {
        Schema::create('device_api_auth_schemas', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., bearer, oauth2_client_credentials, custom_token_login
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('vendor')->nullable(); // purestorage, proxmox, generic
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_api_auth_schemas');
    }
}