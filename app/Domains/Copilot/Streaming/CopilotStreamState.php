<?php

namespace App\Domains\Copilot\Streaming;

use Closure;

/**
 * Mutable state of one streamed Copilot turn, shared by the protocol (which
 * fills it as parts go out) and the turn's callbacks (which persist from it):
 * whether text started and when, the text sent so far, the cards waiting to
 * be sent, the stored message and whether the answer was already stored.
 *
 * `$aborted` tells whether the browser left; tests bind their own.
 */
final class CopilotStreamState
{
    public bool $textStarted = false;

    public ?int $firstTextMs = null;

    /**
     * Text deltas sent to the browser so far, steps separated by a blank line.
     */
    public string $text = '';

    /**
     * The turn started storing its answer (set before storing): nothing
     * stores it again nor falls back, even if that store failed halfway.
     */
    public bool $persisted = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $messagePayload = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $pending = [];

    /**
     * @param  (Closure(): bool)|null  $aborted
     */
    public function __construct(public ?Closure $aborted = null)
    {
        $this->aborted ??= fn (): bool => connection_aborted() === 1;
    }

    public function aborted(): bool
    {
        return ($this->aborted)();
    }
}
