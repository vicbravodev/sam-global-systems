<?php

namespace Database\Factories\Domains\Incidents;

use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentCallVerification>
 */
class IncidentCallVerificationFactory extends Factory
{
    protected $model = IncidentCallVerification::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'incident_id' => Incident::factory(),
            'notification_channel_id' => null,
            'phone' => '+5215512345678',
            'attempt' => 1,
            'status' => CallVerificationStatus::Pending,
            'outcome' => null,
            'digits_received' => null,
            'call_sid' => null,
            'placed_at' => null,
            'responded_at' => null,
            'metadata_json' => null,
        ];
    }

    public function calling(): static
    {
        return $this->state(fn () => [
            'status' => CallVerificationStatus::Calling,
            'call_sid' => 'CA'.fake()->regexify('[a-f0-9]{32}'),
            'placed_at' => now(),
        ]);
    }

    public function confirmedReal(): static
    {
        return $this->state(fn () => [
            'status' => CallVerificationStatus::Answered,
            'outcome' => CallVerificationOutcome::ConfirmedReal,
            'digits_received' => '1',
            'responded_at' => now(),
        ]);
    }
}
