<?php

namespace Database\Factories;

use App\Models\RestApiCredential;
use App\Models\RestApiCredentialParam;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestApiCredentialParamFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RestApiCredentialParam::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'credential_id' => RestApiCredential::factory(),
            'key' => $this->faker->word,
            'value' => $this->faker->password,
        ];
    }
}