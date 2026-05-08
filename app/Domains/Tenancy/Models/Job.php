<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Enums\JobStatus;
use App\Models\User;
use Database\Factories\Domains\Tenancy\JobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'jobs_registry';

    protected $fillable = [
        'team_id',
        'owner_user_id',
        'job_type',
        'jobable_type',
        'jobable_id',
        'status',
        'description',
        'metadata_json',
        'started_at',
        'finished_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function jobable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'metadata_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): JobFactory
    {
        return JobFactory::new();
    }
}
