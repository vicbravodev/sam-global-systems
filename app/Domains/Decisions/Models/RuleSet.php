<?php

namespace App\Domains\Decisions\Models;

use App\Models\Team;
use Database\Factories\Domains\Decisions\RuleSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuleSet extends Model
{
    /** @use HasFactory<RuleSetFactory> */
    use HasFactory;

    protected $table = 'rule_sets';

    protected $fillable = [
        'team_id',
        'code',
        'name',
        'description',
        'version',
        'is_default',
        'is_active',
        'applies_to_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'applies_to_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * @return HasMany<DecisionRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(DecisionRule::class, 'ruleset_id');
    }

    protected static function newFactory(): RuleSetFactory
    {
        return RuleSetFactory::new();
    }
}
