<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run(): void
    {
        $this->call(DefaultAlertTemplateSeeder::class);
        $this->call(ConfigSeeder::class);
        $this->call(RolesSeeder::class);
        $this->call(RestApiAuthenticationTypeSeeder::class);
        $this->call(RestApiTemplateSeeder::class);
        $this->call(PureStorageMappingsSeeder::class);
    }
}
