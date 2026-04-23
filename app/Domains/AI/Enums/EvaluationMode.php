<?php

namespace App\Domains\AI\Enums;

enum EvaluationMode: string
{
    case RulesOnly = 'rules_only';
    case AiText = 'ai_text';
    case Multimodal = 'multimodal';
    case Hybrid = 'hybrid';
    case DeferredPendingMedia = 'deferred_pending_media';
}
