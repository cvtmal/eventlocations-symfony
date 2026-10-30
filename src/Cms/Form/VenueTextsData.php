<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\ChangeVenueTexts;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Texte (D17, S4). `from()` holds the venue's rows; the type adds a field set for every enabled locale, so a
 * locale without a row gets one on submit, and a summary typed there creates the row
 * (`ChangeVenueTextsHandler`; a blank one creates nothing).
 */
final class VenueTextsData implements VenueCardData
{
    use CarriesLoadedAt;

    /** @var array<string, VenueLocaleTextsData> */
    #[Assert\Valid]
    public array $texts = [];

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        foreach ($edit->translations as $locale => $translation) {
            $data->texts[$locale] = VenueLocaleTextsData::from($translation);
        }

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueTexts
    {
        return new ChangeVenueTexts(
            $venueNumber,
            $this->token(),
            $changedFields,
            array_map(static fn (VenueLocaleTextsData $texts): array => $texts->toArray(), $this->texts),
        );
    }
}
