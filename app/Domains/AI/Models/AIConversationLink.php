<?php

namespace App\Domains\AI\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Factories\Domains\AI\AIConversationLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bridges Laravel AI SDK `agent_conversations` rows to a tenant + user so usage
 * metering and multimodal flows can resolve `team_id` from a conversation id.
 *
 * SPEC-09-SDK-DEFERRED: until `laravel/ai` is installed (PR #2b), the
 * `agent_conversation_id` column is unconstrained. The FK to
 * `agent_conversations.id` is added in a follow-up migration once the SDK
 * publishes its own tables.
 */
class AIConversationLink extends Model
{
    /** @use HasFactory<AIConversationLinkFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'ai_conversation_links';

    protected $fillable = [
        'team_id',
        'user_id',
        'agent_conversation_id',
        'normalized_event_id',
        'evaluation_id',
        'purpose',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return BelongsTo<AIEventEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AIEventEvaluation::class, 'evaluation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agent_conversation_id' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): AIConversationLinkFactory
    {
        return AIConversationLinkFactory::new();
    }
}
