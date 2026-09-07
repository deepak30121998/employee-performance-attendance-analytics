<?php

namespace Modules\Performance\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Performance\Models\PerformanceScore;

class PerformanceScoreFactory extends Factory
{
    protected $model = PerformanceScore::class;

    public function definition(): array
    {
        return [
            'employee_id' => User::factory(),
            'month' => fake()->unique()->dateTimeBetween('-6 months', 'now')->format('Y-m-01'),
            'score' => fake()->numberBetween(1, 10),
            'comment' => fake()->optional()->sentence(),
            'created_by' => User::factory()->manager(),
        ];
    }
}
