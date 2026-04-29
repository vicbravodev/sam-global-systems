<?php

namespace App\Domains\Decisions\Models;

use Database\Factories\Domains\Decisions\DecisionOutcomeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DecisionOutcome extends Model
{
    /** @use HasFactory<DecisionOutcomeFactory> */
    use HasFactory;

    protected $table = 'decision_outcomes';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_terminal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
        ];
    }

    protected static function newFactory(): DecisionOutcomeFactory
    {
        return DecisionOutcomeFactory::new();
    }
}
