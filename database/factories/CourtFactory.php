<?php

namespace Database\Factories;

use App\Models\Court;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Court>
 */
class CourtFactory extends Factory
{
    protected $model = Court::class;

    public function definition(): array
    {
        return [
            'name' => 'Court '.fake()->unique()->bothify('##?'),
            'location' => fake()->randomElement(['Indoor', 'Outdoor']),
            'price_per_hour' => 300000,
        ];
    }
}
