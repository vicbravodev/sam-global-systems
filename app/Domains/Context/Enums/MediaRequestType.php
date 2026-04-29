<?php

namespace App\Domains\Context\Enums;

enum MediaRequestType: string
{
    case FetchVideoClip = 'fetch_video_clip';
    case FetchSnapshot = 'fetch_snapshot';
    case FetchDriverCamera = 'fetch_driver_camera';
    case FetchRoadCamera = 'fetch_road_camera';
    case FetchAudio = 'fetch_audio';
}
