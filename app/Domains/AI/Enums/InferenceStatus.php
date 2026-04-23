<?php

namespace App\Domains\AI\Enums;

enum InferenceStatus: string
{
    case Success = 'success';
    case Error = 'error';
    case Timeout = 'timeout';
}
