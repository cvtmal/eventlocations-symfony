<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Cms\ValueObject\TriState;
use App\Venues\Command\ChangeVenueWeddingFacts;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Hochzeit (#31's facts, D17): price, seated and standing capacity, party rules, the four texts per locale
 * that has a row (the handler writes no other), and the six wedding term groups.
 */
final class VenueWeddingData implements VenueCardData
{
    use CarriesLoadedAt;

    /** The groups this card owns, the same as `ChangeVenueWeddingFactsHandler::GROUPS` (pinned by a test). */
    public const array GROUPS = [
        TermGroup::Ceremony,
        TermGroup::Amenity,
        TermGroup::Dining,
        TermGroup::Vendor,
        TermGroup::Usage,
        TermGroup::Language,
    ];

    public ?PriceLevel $priceLevel = null;

    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacitySeated = null;

    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacityStanding = null;

    public ?PartyAllowed $party = null;

    #[Assert\NotNull(message: 'cms.venue.choice.required')]
    public ?TriState $hasCurfew = TriState::Unknown;

    /** `H:i` */
    public ?string $curfewAt = null;

    /** @var array<string, VenueLocaleWeddingData> */
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
        $data->party = $edit->party;
        $data->hasCurfew = TriState::fromBool($edit->hasCurfew);
        $data->curfewAt = $edit->curfewAt;
        foreach ($edit->translations as $locale => $translation) {
            $data->texts[$locale] = VenueLocaleWeddingData::from($translation);
        }
        foreach (self::GROUPS as $group) {
            $data->terms[$group->value] = $edit->terms[$group->value] ?? [];
        }

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueWeddingFacts
    {
        return new ChangeVenueWeddingFacts(
            $venueNumber,
            $this->token(),
            $changedFields,
            $this->priceLevel,
            $this->capacitySeated,
            $this->capacityStanding,
            $this->party,
            ($this->hasCurfew ?? TriState::Unknown)->toBool(),
            $this->curfewAt,
            array_map(static fn (VenueLocaleWeddingData $texts): array => $texts->toArray(), $this->texts),
            $this->terms,
        );
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->curfewAt && TriState::Yes !== $this->hasCurfew) {
            $context->buildViolation('cms.venue.curfew.needs_yes')->atPath('curfewAt')->addViolation();
        }
    }
}
