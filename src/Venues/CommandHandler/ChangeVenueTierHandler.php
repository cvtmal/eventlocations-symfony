<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Repository\TierRepository;
use App\Venues\VenueEditing;

/**
 * The tier orders the listings, a card shows its label and the page its limits, so close() purges the venue and the
 * listings (S10). close() stamps `cms_edited_at` on purpose: without it the next import would overwrite a paid tier
 * (D20).
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
        $this->editing->close($venue);
    }
}
