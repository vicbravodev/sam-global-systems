<?php

namespace App\Domains\Automation\Models;

use App\Domains\Automation\Enums\ActionType;
use App\Models\Team;
use Database\Factories\Domains\Automation\ActionTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionTemplate extends Model
{
    /** @use HasFactory<ActionTemplateFactory> */
    use HasFactory;

    protected $table = 'action_templates';

    protected $fillable = [
        'team_id',
        'code',
        'name',
        'action_type',
        'channel',
        'subject_template',
        'body_template',
        'parameters_schema_json',
        'config_json',
        'is_active',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * Templates accessible to a tenant: tenant-owned plus system-wide (team_id = null).
     *
     * @param  Builder<ActionTemplate>  $query
     */
    public function scopeAvailableToTeam(Builder $query, int $teamId): Builder
    {
        return $query->where(function (Builder $inner) use ($teamId) {
            $inner->where('team_id', $teamId)->orWhereNull('team_id');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'parameters_schema_json' => 'array',
            'config_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): ActionTemplateFactory
    {
        return ActionTemplateFactory::new();
    }
}
