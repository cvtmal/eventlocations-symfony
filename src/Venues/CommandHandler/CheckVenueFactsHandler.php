<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\CheckVenueFacts;
use App\Venues\VenueEditing;
use Psr\Clock\ClockInterface;

/**
 * Sets or clears `venues.facts_checked_at`. An unchanged state writes nothing. The check is outside the content
 * format, so it never stamps `cms_edited_at` (an import keeps applying to the entry); it purges the listings too,
 * because landing pages count indexable entries.
 */
final class CheckVenueFactsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckVenueFacts $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        if ($venue->confirmFacts($command->checked, $this->clock->now())) {
            $this->editing->close($venue, markCmsEdited: false);
        }
    }
}
