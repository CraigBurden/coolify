<?php

namespace Database\Factories;

use App\Models\ImageUpdateCheck;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageUpdateCheck>
 */
class ImageUpdateCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'service_name' => fake()->unique()->slug(2),
            'frequency' => 'daily',
            'on_update' => ImageUpdateCheck::ON_UPDATE_NOTIFY,
        ];
    }
}
