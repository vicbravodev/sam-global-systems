<?php

namespace App\Domains\AI\Models;

use Database\Factories\Domains\AI\AIExplanationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIExplanation extends Model
{
    /** @use HasFactory<AIExplanationFactory> */
    use HasFactory;

    protected $table = 'ai_explanations';

    protected $fillable = [
        'evaluation_id',
        'summary',
        'reasoning_steps_json',
        'key_factors_json',
        'confidence_breakdown_json',
        'evidence_used_json',
    ];

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
            'reasoning_steps_json' => 'array',
            'key_factors_json' => 'array',
            'confidence_breakdown_json' => 'array',
            'evidence_used_json' => 'array',
        ];
    }

    protected static function newFactory(): AIExplanationFactory
    {
        return AIExplanationFactory::new();
    }
}
