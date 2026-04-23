<?php

namespace App\Contracts;

use App\Domains\AI\Data\AIEvaluationResult;

interface AiProviderAdapter
{
    /**
     * Run an evaluation over the structured input context.
     *
     * @param  array<string, mixed>  $input  Output of BuildAIInputContext::execute()
     */
    public function evaluate(array $input): AIEvaluationResult;

    /**
     * Return a short descriptor (model_used string) for logging and auditing.
     */
    public function describe(): string;
}
