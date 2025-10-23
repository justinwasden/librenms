<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure table exists before inserting
        if (!Schema::hasTable('rest_api_authentication_types')) {
            return;
        }

        // Insert 'proxmox' if not present
        $existsProxmox = DB::table('rest_api_authentication_types')
            ->where('name', 'proxmox')
            ->exists();

        if (!$existsProxmox) {
            DB::table('rest_api_authentication_types')->insert([
                'name' => 'proxmox',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Insert 'proxmox-api-token' if not present (optional)
        $existsProxmoxApiToken = DB::table('rest_api_authentication_types')
            ->where('name', 'proxmox-api-token')
            ->exists();

        if (!$existsProxmoxApiToken) {
            DB::table('rest_api_authentication_types')->insert([
                'name' => 'proxmox-api-token',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove the inserted types to reverse the migration cleanly
        if (Schema::hasTable('rest_api_authentication_types')) {
            DB::table('rest_api_authentication_types')
                ->whereIn('name', ['proxmox', 'proxmox-api-token'])
                ->delete();
        }
    }
};