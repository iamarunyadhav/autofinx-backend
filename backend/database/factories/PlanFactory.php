<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => 'Plan ' . strtoupper(Str::random(4)),
            'price' => 19,
            'interval' => 'monthly',
            'features' => ['paper_trading' => true],
        ];
    }
}
