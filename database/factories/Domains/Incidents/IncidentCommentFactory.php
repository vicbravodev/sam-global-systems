<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\CommentVisibility;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentComment>
 */
class IncidentCommentFactory extends Factory
{
    protected $model = IncidentComment::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => User::factory(),
            'comment' => fake()->sentence(),
            'visibility' => CommentVisibility::TenantVisible,
        ];
    }
}
