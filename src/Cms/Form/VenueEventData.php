<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Veranstaltung (the event facts): price, seated and standing capacity, the three texts per locale that has a
 * row (the handler writes no other), and the term groups event types (`suitable_for`), catering, technology, amenity
 * and language (F11: the event step of the wizard asks these).
 */
final class VenueEventData implements VenueCardData
{
    use CarriesLoadedAt;

    /** The groups this card owns, the same as `ChangeVenueEventFactsHandler::GROUPS` (pinned by a test). */
    public const array GROUPS = [
        TermGroup::SuitableFor,
        TermGroup::Catering,
        TermGroup::Technology,
        TermGroup::Amenity,
        TermGroup::Language,
    ];

    public ?PriceLevel $priceLevel = null;

    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacitySeated = null;

    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacityStanding = null;

    /** @var array<string, VenueLocaleEventData> */
    #[Assert\Valid]
    public array $texts = [];

    /** @var array<string, list<string>> TermGroup value → selected codes */
    public array $terms = [];

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->priceLevel = $edit->priceLevel;
        $data->capacitySeated = $edit->capacitySeated;
        $data->capacityStanding = $edit->capacityStanding;
        foreach ($edit->translations as $locale => $translation) {
            $data->texts[$locale] = VenueLocaleEventData::from($translation);
        }
        foreach (self::GROUPS as $group) {
            $data->terms[$group->value] = $edit->terms[$group->value] ?? [];
        }

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueEventFacts
    {
        return new ChangeVenueEventFacts(
            $venueNumber,
            $this->token(),
            $changedFields,
            $this->priceLevel,
            $this->capacitySeated,
            $this->capacityStanding,
            array_map(static fn (VenueLocaleEventData $texts): array => $texts->toArray(), $this->texts),
            $this->terms,
        );
    }
}
