<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\ValueObject\TermGroup;
use App\Venues\VenueEditing;
use App\Venues\VenueTermSet;

/**
 * Replaces the Merkmale groups (setting, building, infrastructure, furniture, surroundings) with the main building and
 * setting; the event groups of `ChangeVenueEventFactsHandler` stay as they are (F11 moved event types and technology there).
 */
final class ChangeVenueTermsHandler implements CommandHandlerInterface
{
    public const array GROUPS = [
        TermGroup::Setting,
        TermGroup::Building,
        TermGroup::Infrastructure,
        TermGroup::Furniture,
        TermGroup::Surroundings,
    ];

    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueTermSet $terms,
    ) {
    }

    public function __invoke(ChangeVenueTerms $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $codes = [];
        foreach (self::GROUPS as $group) {
            $codes[$group->value] = $command->terms[$group->value] ?? [];
        }
        $this->terms->replace($venue, $codes, array_intersect_key($command->main, $codes));

        $this->editing->close($venue);
    }
}
