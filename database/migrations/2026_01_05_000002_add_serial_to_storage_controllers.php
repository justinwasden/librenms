<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_controllers', function (Blueprint $table) {
            if (!Schema::hasColumn('storage_controllers', 'serial')) {
                $table->string('serial', 128)->nullable()->after('model');
            }
        });
    }

    public function down(): void
    {
        Schema::table('storage_controllers', function (Blueprint $table) {
            $table->dropColumn('serial');
        });
    }
};
