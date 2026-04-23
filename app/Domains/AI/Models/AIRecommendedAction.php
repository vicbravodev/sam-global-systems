<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Enums\RecommendedActionType;
use Database\Factories\Domains\AI\AIRecommendedActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIRecommendedAction extends Model
{
    /** @use HasFactory<AIRecommendedActionFactory> */
    use HasFactory;

    protected $table = 'ai_recommended_actions';

    protected $fillable = [
        'evaluation_id',
        'action_type',
        'priority',
        'parameters_json',
        'requires_confirmation',
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
            'action_type' => RecommendedActionType::class,
            'priority' => 'integer',
            'parameters_json' => 'array',
            'requires_confirmation' => 'boolean',
        ];
    }

    protected static function newFactory(): AIRecommendedActionFactory
    {
        return AIRecommendedActionFactory::new();
    }
}
