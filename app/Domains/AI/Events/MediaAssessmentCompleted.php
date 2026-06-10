<?php

namespace App\Domains\AI\Events;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class MediaAssessmentCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Collection<int, AIMediaAssessment>  $assessments  Only the assessments
     *                                                           created by this run — re-processed media that already had an
     *                                                           assessment never re-fires this event.
     */
    public function __construct(
        public readonly AIEventEvaluation $evaluation,
        public readonly Collection $assessments,
    ) {}
}
