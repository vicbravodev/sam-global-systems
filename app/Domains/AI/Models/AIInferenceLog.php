<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Enums\InferenceStatus;
use Database\Factories\Domains\AI\AIInferenceLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIInferenceLog extends Model
{
    /** @use HasFactory<AIInferenceLogFactory> */
    use HasFactory;

    protected $table = 'ai_inference_logs';

    protected $fillable = [
        'evaluation_id',
        'input_snapshot_json',
        'output_json',
        'latency_ms',
        'tokens_used',
        'input_tokens',
        'output_tokens',
        'media_assets_count',
        'cost_estimate',
        'status',
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
            'input_snapshot_json' => 'array',
            'output_json' => 'array',
            'latency_ms' => 'integer',
            'tokens_used' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'media_assets_count' => 'integer',
            'cost_estimate' => 'float',
            'status' => InferenceStatus::class,
        ];
    }

    protected static function newFactory(): AIInferenceLogFactory
    {
        return AIInferenceLogFactory::new();
    }
}
