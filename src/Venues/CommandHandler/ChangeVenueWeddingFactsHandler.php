<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ChangeVenueWeddingFacts;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\TermGroup;
use App\Venues\VenueEditing;
use App\Venues\VenueTermSet;

/**
 * The owner facts of #31 (S14). The curfew time is parsed as ContentReader does, `H:i`
 * with the date part meaningless. Wedding texts go to existing translations only; a locale
 * without a row has no summary yet, and the texts card creates rows.
 */
final class ChangeVenueWeddingFactsHandler implements CommandHandlerInterface
{
    public const array GROUPS = [
        TermGroup::Ceremony,
        TermGroup::Amenity,
        TermGroup::Dining,
        TermGroup::Vendor,
        TermGroup::Usage,
        TermGroup::Language,
    ];

    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRepository $venues,
        private readonly VenueTermSet $terms,
    ) {
    }

    public function __invoke(ChangeVenueWeddingFacts $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        $curfewAt = null;
        if (null !== $command->curfewAt) {
            $curfewAt = \DateTimeImmutable::createFromFormat('!H:i', $command->curfewAt);
            if (false === $curfewAt) {
                throw new \InvalidArgumentException(\sprintf('The curfew "%s" is not H:i.', $command->curfewAt));
            }
        }
        $venue->changePriceLevel($command->priceLevel);
        $venue->changeSeatingCapacity($command->capacitySeated, $command->capacityStanding);
        $venue->describePartyRules($command->party, $command->hasCurfew, $curfewAt);

        $rows = $this->venues->translationsByLocale($venue);
        foreach ($command->texts as $locale => $text) {
            ($rows[$locale] ?? null)?->describeWedding($text['ambience'], $text['capacityNote'], $text['partyNote'], $text['openingHours']);
        }

        $codes = [];
        foreach (self::GROUPS as $group) {
            $codes[$group->value] = $command->terms[$group->value] ?? [];
        }
        $this->terms->replace($venue, $codes, []);

        $this->editing->close($venue);
    }
}
