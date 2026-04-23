<?php

namespace App\Domains\AI\Models;

use Database\Factories\Domains\AI\AIDecisionSignalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIDecisionSignal extends Model
{
    /** @use HasFactory<AIDecisionSignalFactory> */
    use HasFactory;

    protected $table = 'ai_decision_signals';

    protected $fillable = [
        'evaluation_id',
        'signal_code',
        'signal_value',
        'weight',
        'description',
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
            'weight' => 'float',
        ];
    }

    protected static function newFactory(): AIDecisionSignalFactory
    {
        return AIDecisionSignalFactory::new();
    }
}
