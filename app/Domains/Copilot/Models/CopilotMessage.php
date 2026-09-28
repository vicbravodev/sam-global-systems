<?php

namespace App\Domains\Copilot\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Models\User;
use Database\Factories\Domains\Copilot\CopilotMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of a Copilot conversation. Assistant turns carry the structured
 * cards (`blocks_json`) the UI renders and the usage of the turn (tokens,
 * cost, latency) that feeds the usage dashboard.
 */
class CopilotMessage extends Model
{
    /** @use HasFactory<CopilotMessageFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'copilot_conversation_id',
        'user_id',
        'role',
        'content',
        'intent',
        'channel',
        'context_json',
        'blocks_json',
        'tools_json',
        'sources_json',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_estimate',
        'latency_ms',
        'feedback',
    ];

    /**
     * @return BelongsTo<CopilotConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CopilotConversation::class, 'copilot_conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CopilotMessageRole::class,
            'intent' => CopilotIntent::class,
            'context_json' => 'array',
            'blocks_json' => 'array',
            'tools_json' => 'array',
            'sources_json' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_estimate' => 'decimal:6',
            'latency_ms' => 'integer',
            'feedback' => 'integer',
        ];
    }

    protected static function newFactory(): CopilotMessageFactory
    {
        return CopilotMessageFactory::new();
    }
}
