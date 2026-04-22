<?php

namespace Database\Factories\Domains\Access;

use App\Domains\Access\Models\UserPreference;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
class UserPreferenceFactory extends Factory
{
    protected $model = UserPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'preferences_json' => [
                'locale' => fake()->languageCode(),
                'timezone' => fake()->timezone(),
            ],
        ];
    }

    public function globalPreference(): static
    {
        return $this->state(fn () => [
            'team_id' => null,
        ]);
    }
}
