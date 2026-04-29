<?php

namespace App\Domains\Decisions\Models;

use App\Domains\Decisions\Enums\DecisionSourceType;
use Database\Factories\Domains\Decisions\DecisionTraceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionTrace extends Model
{
    /** @use HasFactory<DecisionTraceFactory> */
    use HasFactory;

    protected $table = 'decision_traces';

    protected $fillable = [
        'decision_id',
        'rule_code',
        'source_type',
        'source_reference_id',
        'step_order',
        'input_fragment_json',
        'output_fragment_json',
        'explanation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => DecisionSourceType::class,
            'step_order' => 'integer',
            'input_fragment_json' => 'array',
            'output_fragment_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Decision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'decision_id');
    }

    protected static function newFactory(): DecisionTraceFactory
    {
        return DecisionTraceFactory::new();
    }
}
