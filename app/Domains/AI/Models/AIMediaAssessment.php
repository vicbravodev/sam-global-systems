<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use Database\Factories\Domains\AI\AIMediaAssessmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIMediaAssessment extends Model
{
    /** @use HasFactory<AIMediaAssessmentFactory> */
    use HasFactory;

    protected $table = 'ai_media_assessments';

    protected $fillable = [
        'evaluation_id',
        'event_media_context_id',
        'media_type',
        'assessment_type',
        'result',
        'confidence_score',
        'extracted_signals_json',
        'summary_text',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'cost_estimate',
        'model_used',
        'assessed_at',
    ];

    /**
     * @return BelongsTo<AIEventEvaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(AIEventEvaluation::class, 'evaluation_id');
    }

    /**
     * @return BelongsTo<EventMediaContext, $this>
     */
    public function mediaContext(): BelongsTo
    {
        return $this->belongsTo(EventMediaContext::class, 'event_media_context_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => MediaType::class,
            'assessment_type' => MediaAssessmentType::class,
            'result' => MediaAssessmentResult::class,
            'confidence_score' => 'float',
            'extracted_signals_json' => 'array',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_estimate' => 'float',
            'assessed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AIMediaAssessmentFactory
    {
        return AIMediaAssessmentFactory::new();
    }
}
