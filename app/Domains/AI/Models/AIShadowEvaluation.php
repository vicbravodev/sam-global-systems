<?php

namespace App\Domains\AI\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\AI\AIShadowEvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta de un modelo Clef evaluando en sombra lo mismo que evaluó GPT.
 * Medición temporal: nunca alimenta decisiones ni incidentes.
 */
class AIShadowEvaluation extends Model
{
    /** @use HasFactory<AIShadowEvaluationFactory> */
    use BelongsToTenant, HasFactory;

    public const string STATUS_SUCCESS = 'success';

    public const string STATUS_FAILED = 'failed';

    public const string SOURCE_LIVE = 'live';

    public const string SOURCE_BACKFILL = 'backfill';

    protected $table = 'ai_shadow_evaluations';

    protected $fillable = [
        'team_id',
        'ai_event_evaluation_id',
        'normalized_event_id',
        'model',
        'schema_version',
        'source',
        'status',
        'classification',
        'classification_probabilities_json',
        'risk_score',
        'needs_human_probability',
        'media_answers_json',
        'images_sent',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'cost_estimate',
        'error_code',
    ];

    /**
     * @return BelongsTo<AIEventEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AIEventEvaluation::class, 'ai_event_evaluation_id');
    }

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'classification_probabilities_json' => 'array',
            'media_answers_json' => 'array',
            'risk_score' => 'float',
            'needs_human_probability' => 'float',
            'images_sent' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'cost_estimate' => 'float',
        ];
    }

    protected static function newFactory(): AIShadowEvaluationFactory
    {
        return AIShadowEvaluationFactory::new();
    }
}
