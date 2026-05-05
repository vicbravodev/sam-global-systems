<?php

namespace App\Domains\Context\Enums;

enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Snapshot = 'snapshot';
    case Clip = 'clip';

    public function isVisual(): bool
    {
        return match ($this) {
            self::Image, self::Video, self::Snapshot, self::Clip => true,
            self::Audio => false,
        };
    }
}
