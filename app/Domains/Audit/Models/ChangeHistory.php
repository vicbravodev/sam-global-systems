<?php

namespace App\Domains\Audit\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Audit\Enums\ChangeActorType;
use App\Domains\Audit\Enums\ChangeType;
use App\Domains\Audit\Support\AppendOnly;
use Database\Factories\Domains\Audit\ChangeHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChangeHistory extends Model
{
    /** @use HasFactory<ChangeHistoryFactory> */
    use AppendOnly, BelongsToTenant, HasFactory;

    protected $table = 'change_histories';

    protected $fillable = [
        'team_id',
        'entity_type',
        'entity_id',
        'changed_by_type',
        'changed_by_id',
        'change_type',
        'before_json',
        'after_json',
        'changed_fields_json',
        'reason',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_by_type' => ChangeActorType::class,
            'change_type' => ChangeType::class,
            'before_json' => 'array',
            'after_json' => 'array',
            'changed_fields_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ChangeHistoryFactory
    {
        return ChangeHistoryFactory::new();
    }
}
