<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\TierLabel;

/**
 * A won deal's tier that the venue does not have yet: what "Tarif anwenden" would change. `current` goes into the form as
 * `expected`, so a tier that moved while the form was open is a conflict.
 */
final readonly class TierOffer
{
    public function __construct(
        public string $dealId,
        public string $venueName,
        public TierLabel $current,
        public TierLabel $offered,
    ) {
    }
}
