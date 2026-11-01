<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\VenueEditing;

/**
 * `tier_rank` orders the listings and a card shows the badge, so the listings are purged too. close() stamps
 * `cms_edited_at` on purpose: without it the next import would write the folder's tier over a paid one (D20).
 */
final class ChangeVenueTierHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
    ) {
    }

    public function __invoke(ChangeVenueTier $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        if ($command->tier === $venue->tier()) {
            return;
        }

        $venue->changeTier($command->tier);
        $this->editing->close($venue);
    }
}
