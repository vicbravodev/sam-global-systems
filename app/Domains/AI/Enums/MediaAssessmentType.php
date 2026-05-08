<?php

namespace App\Domains\AI\Enums;

enum MediaAssessmentType: string
{
    case VisualValidation = 'visual_validation';
    case ClipReview = 'clip_review';
    case ImageCheck = 'image_check';
    case AudioCheck = 'audio_check';
    case ObstructionCheck = 'obstruction_check';
    case BehavioralCheck = 'behavioral_check';
}
