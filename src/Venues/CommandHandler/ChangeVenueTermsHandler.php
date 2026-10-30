<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\ValueObject\TermGroup;
use App\Venues\VenueEditing;
use App\Venues\VenueTermSet;

/**
 * Replaces the seven filter groups; the six wedding groups stay as they are.
 */
final class ChangeVenueTermsHandler implements CommandHandlerInterface
{
    public const array GROUPS = [
        TermGroup::Setting,
        TermGroup::Building,
        TermGroup::SuitableFor,
        TermGroup::Infrastructure,
        TermGroup::Furniture,
        TermGroup::Technology,
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
