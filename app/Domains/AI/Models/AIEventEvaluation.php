<?php

namespace App\Domains\AI\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\AI\AIEventEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AIEventEvaluation extends Model
{
    /** @use HasFactory<AIEventEvaluationFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'ai_event_evaluations';

    protected $fillable = [
        'normalized_event_id',
        'team_id',
        'evaluation_version',
        'evaluation_mode',
        'classification',
        'confidence_score',
        'risk_score',
        'priority_level',
        'is_real_event',
        'requires_action',
        'recommended_action',
        'explanation_text',
        'signals_json',
        'evidence_summary_json',
        'model_used',
        'evaluated_at',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return HasMany<AIDecisionSignal, $this>
     */
    public function decisionSignals(): HasMany
    {
        return $this->hasMany(AIDecisionSignal::class, 'evaluation_id');
    }

    /**
     * @return HasMany<AIRecommendedAction, $this>
     */
    public function recommendedActions(): HasMany
    {
        return $this->hasMany(AIRecommendedAction::class, 'evaluation_id');
    }

    /**
     * @return HasOne<AIExplanation, $this>
     */
    public function explanation(): HasOne
    {
        return $this->hasOne(AIExplanation::class, 'evaluation_id');
    }

    /**
     * @return HasMany<AIInferenceLog, $this>
     */
    public function inferenceLogs(): HasMany
    {
        return $this->hasMany(AIInferenceLog::class, 'evaluation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evaluation_version' => 'integer',
            'evaluation_mode' => EvaluationMode::class,
            'classification' => EventClassification::class,
            'priority_level' => EvaluationPriority::class,
            'confidence_score' => 'decimal:2',
            'risk_score' => 'decimal:2',
            'is_real_event' => 'boolean',
            'requires_action' => 'boolean',
            'signals_json' => 'array',
            'evidence_summary_json' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AIEventEvaluationFactory
    {
        return AIEventEvaluationFactory::new();
    }
}
