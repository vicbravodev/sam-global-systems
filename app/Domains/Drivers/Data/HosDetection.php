<?php

namespace App\Domains\Drivers\Data;

use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;

final readonly class HosDetection
{
    /**
     * @param  array<int, HosSituation>  $open  situations to open now
     * @param  array<string, HosEpisodeResolution>  $resolve  open situation value → how it ends
     */
    public function __construct(
        public array $open,
        public array $resolve,
    ) {}
}
