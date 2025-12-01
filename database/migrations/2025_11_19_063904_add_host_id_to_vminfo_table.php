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
        Schema::table('vminfo', function (Blueprint $table) {
            $table->string('vmwVmHostId', 128)->nullable()->after('vmwVmVMID')->comment('Hypervisor host/node ID where VM is running');
            $table->index('vmwVmHostId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vminfo', function (Blueprint $table) {
            $table->dropIndex(['vmwVmHostId']);
            $table->dropColumn('vmwVmHostId');
        });
    }
};
