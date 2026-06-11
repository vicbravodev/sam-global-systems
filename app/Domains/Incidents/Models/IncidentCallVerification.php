<?php

namespace App\Domains\Incidents\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Notifications\Models\NotificationChannel;
use Database\Factories\Domains\Incidents\IncidentCallVerificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt of the operator voice-verification call for a panic incident
 * (Roadmap V2-A3): SAM calls the operator, reads the incident aloud and
 * gathers a DTMF digit — 1 confirms a real emergency, 2 flags a false alarm.
 * Unanswered attempts chain up to the tenant's `voice.call_attempts`.
 */
class IncidentCallVerification extends Model
{
    /** @use HasFactory<IncidentCallVerificationFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'incident_id',
        'notification_channel_id',
        'phone',
        'attempt',
        'status',
        'outcome',
        'digits_received',
        'call_sid',
        'placed_at',
        'responded_at',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => CallVerificationStatus::class,
            'outcome' => CallVerificationOutcome::class,
            'placed_at' => 'datetime',
            'responded_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): IncidentCallVerificationFactory
    {
        return IncidentCallVerificationFactory::new();
    }
}
