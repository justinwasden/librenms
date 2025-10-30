<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ipv4_addresses', function (Blueprint $table) {
            // Ensure consistent uniqueness per port+address+context
            $table->unique(['port_id', 'ipv4_address', 'context_name'], 'ipv4_addresses_port_ip_context_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ipv4_addresses', function (Blueprint $table) {
            $table->dropUnique('ipv4_addresses_port_ip_context_unique');
        });
    }
};
