<?php

namespace App\Domains\Normalization\Events;

use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Una emergencia llegó de una unidad que el tenant NO vigila (`pending` o
 * `excluded`). El evento se atiende igual (ya se despachó EventNormalized);
 * esto sólo avisa a facturación y al admin del uso extra.
 */
class UnmonitoredAssetEmergencyReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NormalizedEvent $normalizedEvent,
    ) {}
}
