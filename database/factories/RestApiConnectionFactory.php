<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\RestApiConnection;
use App\Models\RestApiCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestApiConnectionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RestApiConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'device_id' => Device::factory(),
            'credential_id' => RestApiCredential::factory(),
            'name' => $this->faker->company,
            'base_url' => $this->faker->url,
            'rate_limit' => $this->faker->numberBetween(60, 300),
        ];
    }
}