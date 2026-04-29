<?php

namespace App\Domains\Incidents\Models;

use App\Domains\Incidents\Enums\CommentVisibility;
use App\Models\User;
use Database\Factories\Domains\Incidents\IncidentCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentComment extends Model
{
    /** @use HasFactory<IncidentCommentFactory> */
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'user_id',
        'comment',
        'visibility',
    ];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => CommentVisibility::class,
        ];
    }

    protected static function newFactory(): IncidentCommentFactory
    {
        return IncidentCommentFactory::new();
    }
}
