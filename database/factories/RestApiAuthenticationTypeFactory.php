<?php

namespace Database\Factories;

use App\Models\RestApiAuthenticationType;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestApiAuthenticationTypeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RestApiAuthenticationType::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->word,
        ];
    }
}