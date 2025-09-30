<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RestApiAuthenticationType;

class RestApiAuthenticationTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        RestApiAuthenticationType::firstOrCreate(['name' => 'Basic Auth']);
        RestApiAuthenticationType::firstOrCreate(['name' => 'Token']);
    }
}