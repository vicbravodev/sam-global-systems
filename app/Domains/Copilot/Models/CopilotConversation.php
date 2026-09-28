<?php

namespace App\Domains\Copilot\Models;

use App\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\Domains\Copilot\CopilotConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SAM Copilot thread. Private to the user who started it, inside one tenant.
 */
class CopilotConversation extends Model
{
    /** @use HasFactory<CopilotConversationFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'user_id',
        'title',
        'is_pinned',
        'messages_count',
        'last_message_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CopilotMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CopilotMessage::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'messages_count' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CopilotConversationFactory
    {
        return CopilotConversationFactory::new();
    }
}
