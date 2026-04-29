<?php

namespace App\Domains\Decisions\Models;

use App\Domains\Decisions\Enums\RuleScope;
use App\Models\Team;
use Database\Factories\Domains\Decisions\DecisionRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionRule extends Model
{
    /** @use HasFactory<DecisionRuleFactory> */
    use HasFactory;

    protected $table = 'decision_rules';

    protected $fillable = [
        'team_id',
        'ruleset_id',
        'code',
        'name',
        'description',
        'scope',
        'priority',
        'conditions_json',
        'outcome_override',
        'escalation_policy_id',
        'automation_action_id',
        'stop_processing',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RuleScope::class,
            'priority' => 'integer',
            'conditions_json' => 'array',
            'stop_processing' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
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
    public function outcomeOverride(): BelongsTo
    {
        return $this->belongsTo(DecisionOutcome::class, 'outcome_override');
    }

    /**
     * @return BelongsTo<EscalationPolicy, $this>
     */
    public function escalationPolicy(): BelongsTo
    {
        return $this->belongsTo(EscalationPolicy::class, 'escalation_policy_id');
    }

    protected static function newFactory(): DecisionRuleFactory
    {
        return DecisionRuleFactory::new();
    }
}
