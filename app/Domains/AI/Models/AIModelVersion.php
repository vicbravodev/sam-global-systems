<?php

namespace App\Domains\AI\Models;

use App\Domains\AI\Enums\AIModelType;
use Database\Factories\Domains\AI\AIModelVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AIModelVersion extends Model
{
    /** @use HasFactory<AIModelVersionFactory> */
    use HasFactory;

    protected $table = 'ai_model_versions';

    protected $fillable = [
        'name',
        'version',
        'model_type',
        'provider',
        'modality_support_json',
        'config_json',
        'deployed_at',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'model_type' => AIModelType::class,
            'modality_support_json' => 'array',
            'config_json' => 'array',
            'deployed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): AIModelVersionFactory
    {
        return AIModelVersionFactory::new();
    }
}
