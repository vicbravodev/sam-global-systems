<?php

namespace App\Domains\Audit\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Audit\Enums\TraceRelationType;
use App\Domains\Audit\Support\AppendOnly;
use Database\Factories\Domains\Audit\TraceLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TraceLink extends Model
{
    /** @use HasFactory<TraceLinkFactory> */
    use AppendOnly, BelongsToTenant, HasFactory;

    protected $table = 'trace_links';

    protected $fillable = [
        'team_id',
        'trace_id',
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'relation_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation_type' => TraceRelationType::class,
        ];
    }

    protected static function newFactory(): TraceLinkFactory
    {
        return TraceLinkFactory::new();
    }
}
