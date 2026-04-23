<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Models\AIEventEvaluation;
use Illuminate\Support\Collection;

/**
 * SPEC-09-PR2-DEFERRED: Multimodal evaluation (media-aware AI) is deferred to
 * the second PR of spec 09. It depends on:
 *   - spec 08 PR #2 (event_media_contexts + media pipeline)
 *   - Laravel AI SDK installation
 * When PR #2 lands, this stub must be replaced with iteration over media
 * contexts, multimodal agent calls, and creation of AIMediaAssessment records.
 */
class EvaluateEventMultimodally
{
    /**
     * @param  Collection<int, object>  $mediaContexts
     */
    public function execute(AIEventEvaluation $evaluation, Collection $mediaContexts): ?object
    {
        return null;
    }
}
