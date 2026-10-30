<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RelocateVenue;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Exception\UnresolvedTown;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\Repository\VenueRepository;
use App\Venues\TownResolver;
use App\Venues\ValueObject\TownText;
use App\Venues\VenueEditing;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The address card (#24, D1–D4, L1):
 *
 * 1. "PLZ / Ort" resolves to one town by ZIP and name; only when that is ambiguous does the
 *    canton select narrow it (a wrong select never overrides an unambiguous town). The typed
 *    ZIP is kept ("8005 Zürich" stays 8005).
 * 2. "Ortsmitte übernehmen" takes the town's centre and flags it (coordinates_from_town), so
 *    no map pin or geo data claims it; coordinates typed or cleared drop the flag, and
 *    untouched ones keep it.
 * 3. A town needs coordinates, typed or the centre; coordinates without a town stay valid.
 * 4. A press of the button on a venue already at its town centre changes nothing, so it
 *    returns before close() and an idle click blocks no import.
 * 5. The canton follows the town, else stands alone; once either exists the region text,
 *    meant only for a venue without a canton, is cleared.
 */
final class RelocateVenueHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRepository $venues,
        private readonly TownResolver $towns,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RelocateVenue $command): void
    {
        $venue = $this->editing->open($command->venueNumber, $command->loadedAt);

        [$town, $zip] = $this->resolveTown($command);
        if ($command->useTownCentre && null === $town) {
            throw VenueRuleViolation::townCentreNeedsTown();
        }
        if (null !== $town && $command->useTownCentre) {
            [$lat, $lng, $fromTown] = [$town->lat(), $town->lng(), true];
        } else {
            [$lat, $lng] = [$command->lat, $command->lng];
            $fromTown = $venue->coordinatesFromTown() && null !== $lat && $lat === $venue->lat() && $lng === $venue->lng();
        }
        if (null !== $town && (null === $lat || null === $lng)) {
            throw VenueRuleViolation::coordinatesRequired();
        }

        $canton = null === $town && null !== $command->cantonCode ? $this->em->getReference(Canton::class, $command->cantonCode) : null;
        $hasCantonAfter = null !== $town || null !== $canton;
        $rows = $this->venues->translationsByLocale($venue);
        $regions = [];
        foreach ($rows as $locale => $row) {
            $regions[$locale] = $hasCantonAfter ? null : self::blankToNull($command->regions[$locale] ?? null);
        }

        if ($this->unchanged($venue, $command, $town, $zip, $canton, $lat, $lng, $fromTown, $rows, $regions)) {
            return;
        }

        $venue->relocate($town, $zip, $command->street, $lat, $lng, $canton, $fromTown);
        $venue->changeDistances(
            $command->stationKm,
            $command->stationName,
            $command->highwayKm,
            $command->highwayName,
            $command->airportKm,
            $command->airportName,
        );
        foreach ($rows as $locale => $row) {
            if ($row->region() !== $regions[$locale]) {
                $row->changeRegion($regions[$locale]);
            }
        }

        $this->editing->close($venue);
    }

    /**
     * @return array{?Town, ?string} the town and the ZIP as typed
     *
     * @throws VenueRuleViolation
     */
    private function resolveTown(RelocateVenue $command): array
    {
        if (null === $command->town || '' === trim($command->town)) {
            return [null, null];
        }
        $text = TownText::parse($command->town) ?? throw VenueRuleViolation::unknownTown();

        try {
            return [$this->towns->resolve($text->zip, $text->name, null), $text->zip];
        } catch (UnresolvedTown $unresolved) {
            if (!$unresolved->isAmbiguous()) {
                throw VenueRuleViolation::unknownTown();
            }
            if (null === $command->cantonCode) {
                throw VenueRuleViolation::ambiguousTown();
            }
        }

        try {
            return [$this->towns->resolve($text->zip, $text->name, $command->cantonCode), $text->zip];
        } catch (UnresolvedTown) {
            throw VenueRuleViolation::ambiguousTown();
        }
    }

    /**
     * @param array<string, VenueTranslation> $rows
     * @param array<string, ?string>          $regions locale → the region after the save
     */
    private function unchanged(
        Venue $venue,
        RelocateVenue $command,
        ?Town $town,
        ?string $zip,
        ?Canton $canton,
        ?float $lat,
        ?float $lng,
        bool $fromTown,
        array $rows,
        array $regions,
    ): bool {
        $cantonAfter = $town?->canton()->code() ?? $canton?->code();
        foreach ($rows as $locale => $row) {
            if ($row->region() !== $regions[$locale]) {
                return false;
            }
        }

        return $venue->town()?->id() === $town?->id()
            && $venue->zip() === $zip
            && $venue->street() === $command->street
            && $venue->canton()?->code() === $cantonAfter
            && $venue->lat() === $lat
            && $venue->lng() === $lng
            && $venue->coordinatesFromTown() === $fromTown
            && $venue->distanceStationKm() === self::toFloat($command->stationKm)
            && $venue->stationName() === $command->stationName
            && $venue->distanceHighwayKm() === self::toFloat($command->highwayKm)
            && $venue->highwayName() === $command->highwayName
            && $venue->distanceAirportKm() === self::toFloat($command->airportKm)
            && $venue->airportName() === $command->airportName;
    }

    private static function toFloat(?string $decimal): ?float
    {
        return null === $decimal ? null : (float) $decimal;
    }

    private static function blankToNull(?string $text): ?string
    {
        return null === $text || '' === trim($text) ? null : $text;
    }
}
