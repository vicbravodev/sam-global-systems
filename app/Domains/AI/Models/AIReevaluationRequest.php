<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\AI\AIReevaluationRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIReevaluationRequest extends Model
{
    /** @use HasFactory<AIReevaluationRequestFactory> */
    use HasFactory;

    protected $table = 'ai_reevaluation_requests';

    protected $fillable = [
        'normalized_event_id',
        'trigger_type',
        'trigger_reference_id',
        'reason',
        'status',
        'requested_at',
        'processed_at',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => ReevaluationTrigger::class,
            'status' => ReevaluationStatus::class,
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AIReevaluationRequestFactory
    {
        return AIReevaluationRequestFactory::new();
    }
}
