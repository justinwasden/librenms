<?php

namespace Database\Factories;

use App\Models\RestApiConnection;
use App\Models\RestApiEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestApiEndpointFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RestApiEndpoint::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'connection_id' => RestApiConnection::factory(),
            'name' => $this->faker->word,
            'path' => '/' . $this->faker->slug,
            'method' => 'GET',
            'metric_map' => ['test_metric' => 'data.value'],
        ];
    }
}