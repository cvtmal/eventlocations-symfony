<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\PublishVenue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\VenueEditing;
use Doctrine\DBAL\Connection;

/**
 * CMS-3, D14: a venue goes online only with a summary in the default locale, a main
 * building and at least one gallery photo (`room_id IS NULL`; a room's photo is not on the
 * card). Every gap is named at once. Publishing an online venue changes nothing.
 */
final class PublishVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly Connection $connection,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(PublishVenue $command): void
    {
        $venue = $this->editing->lock($command->venueNumber);
        if ($venue->isPublished()) {
            return;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    EXISTS (SELECT 1 FROM venue_translations t WHERE t.venue_id = :venue AND t.locale = :locale AND btrim(t.summary) <> '') AS summary,
                    EXISTS (SELECT 1 FROM venue_terms vt WHERE vt.venue_id = :venue AND vt.term_group = 'building' AND vt.is_main) AS main_building,
                    EXISTS (SELECT 1 FROM venue_photos p WHERE p.venue_id = :venue AND p.room_id IS NULL) AS photo
                SQL,
            ['venue' => $venue->id(), 'locale' => $this->locales->default()],
        );
        \assert(false !== $row);
        $missing = array_keys(array_filter($row, static fn (mixed $present): bool => !$present));
        if ([] !== $missing) {
            throw VenueRuleViolation::notPublishable(array_map(strval(...), $missing));
        }

        $venue->publish();
        $this->editing->close($venue);
    }
}
