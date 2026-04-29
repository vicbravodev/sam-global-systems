<?php

namespace App\Domains\Decisions\Models;

use App\Models\User;
use Database\Factories\Domains\Decisions\DecisionOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionOverride extends Model
{
    /** @use HasFactory<DecisionOverrideFactory> */
    use HasFactory;

    protected $table = 'decision_overrides';

    protected $fillable = [
        'decision_id',
        'overridden_by_user_id',
        'previous_outcome',
        'new_outcome',
        'reason',
    ];

    /**
     * @return BelongsTo<Decision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'decision_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_user_id');
    }

    protected static function newFactory(): DecisionOverrideFactory
    {
        return DecisionOverrideFactory::new();
    }
}
