<?php

namespace App\Domains\Decisions\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Decisions\DecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Decision extends Model
{
    /** @use HasFactory<DecisionFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'decisions';

    protected $fillable = [
        'normalized_event_id',
        'team_id',
        'ai_evaluation_id',
        'ruleset_id',
        'decision_code',
        'decision_reason',
        'priority_level',
        'requires_human_review',
        'is_automated',
        'escalation_policy_id',
        'outcome_id',
        'context_snapshot_id',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority_level' => DecisionPriority::class,
            'requires_human_review' => 'boolean',
            'is_automated' => 'boolean',
            'decided_at' => 'datetime',
        ];
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
    public function aiEvaluation(): BelongsTo
    {
        return $this->belongsTo(AIEventEvaluation::class, 'ai_evaluation_id');
    }

    /**
     * @return BelongsTo<RuleSet, $this>
     */
    public function ruleset(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class, 'ruleset_id');
    }

    /**
     * @return BelongsTo<DecisionOutcome, $this>
     */
    public function outcome(): BelongsTo
    {
        return $this->belongsTo(DecisionOutcome::class, 'outcome_id');
    }

    /**
     * @return BelongsTo<EscalationPolicy, $this>
     */
    public function escalationPolicy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class, 'escalation_policy_id');
    }

    /**
     * @return HasMany<DecisionTrace, $this>
     */
    public function traces(): HasMany
    {
        return $this->hasMany(DecisionTrace::class, 'decision_id')->orderBy('step_order');
    }

    /**
     * @return HasMany<DecisionOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(DecisionOverride::class, 'decision_id');
    }

    protected static function newFactory(): DecisionFactory
    {
        return DecisionFactory::new();
    }
}
