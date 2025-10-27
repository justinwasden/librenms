<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApiAuthSchemaFieldsTable extends Migration
{
    public function up(): void
    {
        Schema::create('api_auth_schema_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schema_id')->constrained('api_auth_schemas')->onDelete('cascade');
            $table->string('name');        // e.g., api_bearer_token, api_client_id
            $table->string('label');       // UI label
            $table->string('type');        // text, password, number, select, checkbox, json, url
            $table->boolean('required')->default(false);
            $table->boolean('encrypted')->default(false);
            $table->string('default')->nullable();
            $table->string('placeholder')->nullable();
            $table->json('options')->nullable(); // for select inputs
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->unique(['schema_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_auth_schema_fields');
    }
}