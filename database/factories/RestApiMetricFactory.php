<?php

namespace Database\Factories;

use App\Models\RestApiEndpoint;
use App\Models\RestApiMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestApiMetricFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RestApiMetric::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'endpoint_id' => RestApiEndpoint::factory(),
            'metric_name' => $this->faker->word,
            'metric_value' => (string) $this->faker->randomFloat(2, 0, 1000),
            'collected_at' => now(),
        ];
    }
}