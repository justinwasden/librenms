<?php

namespace Database\Factories;

use App\Models\RestApiAuthenticationType;
use App\Models\RestApiCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestApiCredentialFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RestApiCredential::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->company,
            'authentication_type_id' => RestApiAuthenticationType::factory(),
        ];
    }
}