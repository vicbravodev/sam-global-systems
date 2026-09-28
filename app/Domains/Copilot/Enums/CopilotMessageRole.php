<?php

namespace App\Domains\Copilot\Enums;

enum CopilotMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
