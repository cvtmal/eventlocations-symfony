<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

/**
 * What became of one venue editor card's submission (`VenueCardSaver`).
 */
enum VenueCardSaveOutcome
{
    case Saved;
    case Unchanged;
    case Invalid;
    case Stale;
}
