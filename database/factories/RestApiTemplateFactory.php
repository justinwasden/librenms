<?php

namespace Database\Factories;

use App\Models\RestApiTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class RestApiTemplateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RestApiTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->bs,
            'vendor' => $this->faker->company,
            'template_data' => [
                'connections' => [
                    [
                        'name' => 'Test Connection',
                        'base_url' => $this->faker->url,
                        'endpoints' => [
                            [
                                'name' => 'Test Endpoint',
                                'path' => '/test',
                                'metric_map' => ['test_metric' => 'data.value'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}