<?php

namespace Database\Factories\Domains\Decisions;

use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Models\DecisionOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecisionOutcome>
 */
class DecisionOutcomeFactory extends Factory
{
    protected $model = DecisionOutcome::class;

    public function definition(): array
    {
        return [
            'code' => DecisionOutcomeCode::LogOnly->value,
            'name' => 'Log Only',
            'description' => 'Record the event without further action.',
            'is_terminal' => true,
        ];
    }

    public function code(DecisionOutcomeCode|string $code): static
    {
        $value = $code instanceof DecisionOutcomeCode ? $code->value : $code;

        return $this->state(fn () => [
            'code' => $value,
            'name' => str_replace('_', ' ', ucwords(strtolower($value), '_')),
        ]);
    }
}
