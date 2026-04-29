<?php

namespace App\Domains\Automation\Models;

use App\Domains\Automation\Enums\ActionLogType;
use Database\Factories\Domains\Automation\ActionExecutionLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionExecutionLog extends Model
{
    /** @use HasFactory<ActionExecutionLogFactory> */
    use HasFactory;

    protected $table = 'action_execution_logs';

    protected $fillable = [
        'action_execution_id',
        'log_type',
        'message',
        'payload_json',
    ];

    /**
     * @return BelongsTo<ActionExecution, $this>
     */
    public function actionExecution(): BelongsTo
    {
        return $this->belongsTo(ActionExecution::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'log_type' => ActionLogType::class,
            'payload_json' => 'array',
        ];
    }

    protected static function newFactory(): ActionExecutionLogFactory
    {
        return ActionExecutionLogFactory::new();
    }
}
