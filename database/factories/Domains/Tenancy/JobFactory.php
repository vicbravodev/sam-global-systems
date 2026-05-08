<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Enums\JobStatus;
use App\Domains\Tenancy\Models\Job;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Job>
 */
class JobFactory extends Factory
{
    protected $model = Job::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'owner_user_id' => User::factory(),
            'job_type' => fake()->randomElement(['ai_streaming', 'report_export', 'sync_run']),
            'jobable_type' => null,
            'jobable_id' => null,
            'status' => JobStatus::Pending,
            'description' => null,
            'metadata_json' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function forMorph(string $type, int|string $id): static
    {
        return $this->state(fn () => [
            'jobable_type' => $type,
            'jobable_id' => $id,
        ]);
    }

    public function status(JobStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_user_id' => $user->id]);
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn () => ['team_id' => $team->id]);
    }
}
