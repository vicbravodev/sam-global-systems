<?php

namespace App\Domains\Assets\Enums;

/**
 * Por qué se cerró un enganche tracto–remolque.
 */
enum CouplingDecoupleReason: string
{
    /** El remolque se mueve junto a otro tracto. */
    case Switched = 'switched';

    /** El remolque se mueve, pero lejos de su tracto. */
    case Diverged = 'diverged';

    /** El tracto maneja y el remolque se quedó quieto lejos de él. */
    case LeftBehind = 'left_behind';
}
