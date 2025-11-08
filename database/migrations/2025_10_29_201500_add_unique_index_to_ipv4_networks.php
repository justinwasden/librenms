<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ipv4_networks', function (Blueprint $table) {
            // Add unique index to align with upsert behavior (ipv4_network + context_name)
            // Use a conventional name to avoid collisions
            $table->unique(['ipv4_network', 'context_name'], 'ipv4_networks_network_context_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ipv4_networks', function (Blueprint $table) {
            $table->dropUnique('ipv4_networks_network_context_unique');
        });
    }
};
