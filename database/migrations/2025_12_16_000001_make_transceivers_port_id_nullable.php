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
        Schema::table('transceivers', function (Blueprint $table) {
            $table->unsignedBigInteger('port_id')->nullable()->change();
            $table->string('oui', 8)->nullable()->change();
            $table->string('revision', 16)->nullable()->change();
            $table->string('date', 16)->nullable()->change();
            $table->boolean('ddm')->nullable()->change();
            $table->string('encoding', 64)->nullable()->change();
            $table->string('cable', 64)->nullable()->change();
            $table->integer('distance')->nullable()->change();
            $table->integer('wavelength')->nullable()->change();
            $table->integer('channels')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transceivers', function (Blueprint $table) {
            $table->unsignedBigInteger('port_id')->nullable(false)->change();
        });
    }
};
