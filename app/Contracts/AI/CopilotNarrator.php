<?php

namespace App\Contracts\AI;

use App\Domains\Copilot\Data\CopilotAnswer;
use App\Domains\Copilot\Data\CopilotNarration;

/**
 * Turns the grounded tool output of a Copilot turn into the reply the
 * operator reads. Implementations must never add facts that are not in
 * `$answer`: the cards are the source of truth, the text only explains them.
 */
interface CopilotNarrator
{
    /**
     * @param  list<array{role: string, content: string}>  $history  Previous turns, oldest first.
     */
    public function narrate(string $question, CopilotAnswer $answer, array $history = []): CopilotNarration;
}
