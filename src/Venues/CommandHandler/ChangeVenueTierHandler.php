<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Repository\TierRepository;
use App\Venues\VenueEditing;

/**
 * The tier orders the listings, a card shows its label and the page its limits, so close() purges the venue and the
 * listings (S10). It does not stamp `cms_edited_at`: the import no longer reads a tier, so nothing needs protecting,
 * and a deal applied, or G9's nightly expiry, must not freeze the venue against every later folder import. Like the
 * Google place commands, the entity moves `updated_at` itself, so other open forms still go stale.
 */
final class ChangeVenueTierHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly TierRepository $tiers,
    ) {
    }

    public function __invoke(ChangeVenueTier $command): void
    {
        $tier = $this->tiers->byCode($command->tierCode);
        $venue = $this->editing->lock($command->venueNumber);
        if ($tier->id() === $venue->tier()->id()) {
            return;
        }

        $venue->changeTier($tier);
        $this->editing->close($venue, markCmsEdited: false);
    }
}
