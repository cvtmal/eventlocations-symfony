<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEditTranslation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Veranstaltung card's texts of one locale, kept verbatim.
 */
final class VenueLocaleEventData
{
    #[Assert\Length(max: 60, maxMessage: 'cms.venue.too_long')]
    public ?string $ambience = null;

    #[Assert\Length(max: 250, maxMessage: 'cms.venue.too_long')]
    public ?string $capacityNote = null;

    #[Assert\Length(max: 250, maxMessage: 'cms.venue.too_long')]
    public ?string $openingHours = null;

    public static function from(VenueEditTranslation $translation): self
    {
        $data = new self();
        $data->ambience = $translation->ambience;
        $data->capacityNote = $translation->capacityNote;
        $data->openingHours = $translation->openingHours;

        return $data;
    }

    /**
     * @return array{ambience: ?string, capacityNote: ?string, openingHours: ?string}
     */
    public function toArray(): array
    {
        return [
            'ambience' => $this->ambience,
            'capacityNote' => $this->capacityNote,
            'openingHours' => $this->openingHours,
        ];
    }
}
