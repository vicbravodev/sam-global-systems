<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventMediaRequest>
 */
class EventMediaRequestFactory extends Factory
{
    protected $model = EventMediaRequest::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'normalized_event_id' => NormalizedEvent::factory(),
            'provider_id' => IntegrationProvider::factory(),
            'request_type' => MediaRequestType::FetchVideoClip,
            'requested_at' => now(),
            'status' => MediaRequestStatus::Pending,
            'response_metadata_json' => null,
            'expires_at' => now()->addHours(6),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => MediaRequestStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => MediaRequestStatus::Failed,
            'completed_at' => now(),
        ]);
    }
}
