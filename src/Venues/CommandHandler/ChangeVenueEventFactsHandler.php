<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\TermGroup;
use App\Venues\VenueEditing;
use App\Venues\VenueTermSet;

/**
 * The event facts of #31 (S14) after event-vocabulary (F3). The texts go to existing
 * translations only; a locale without a row has no summary yet, and the texts card creates
 * rows. The three groups are replaced as a whole, the seven filter groups stay as they are.
 */
final class ChangeVenueEventFactsHandler implements CommandHandlerInterface
{
    public const array GROUPS = [
        TermGroup::Amenity,
        TermGroup::Catering,
        TermGroup::Language,
    ];

    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRepository $venues,
        private readonly VenueTermSet $terms,
    ) {
    }

    public function __invoke(ChangeVenueEventFacts $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $venue->changePriceLevel($command->priceLevel);
        $venue->changeSeatingCapacity($command->capacitySeated, $command->capacityStanding);

        $rows = $this->venues->translationsByLocale($venue);
        foreach ($command->texts as $locale => $text) {
            ($rows[$locale] ?? null)?->describeEvent($text['ambience'], $text['capacityNote'], $text['openingHours']);
        }

        $codes = [];
        foreach (self::GROUPS as $group) {
            $codes[$group->value] = $command->terms[$group->value] ?? [];
        }
        $this->terms->replace($venue, $codes, []);

        $this->editing->close($venue);
    }
}
