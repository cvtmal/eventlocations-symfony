<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEditTranslation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Hochzeit card's texts of one locale (#31), kept verbatim.
 */
final class VenueLocaleWeddingData
{
    #[Assert\Length(max: 60, maxMessage: 'cms.venue.too_long')]
    public ?string $ambience = null;

    #[Assert\Length(max: 250, maxMessage: 'cms.venue.too_long')]
    public ?string $capacityNote = null;

    #[Assert\Length(max: 250, maxMessage: 'cms.venue.too_long')]
    public ?string $partyNote = null;

    #[Assert\Length(max: 250, maxMessage: 'cms.venue.too_long')]
    public ?string $openingHours = null;

    public static function from(VenueEditTranslation $translation): self
    {
        $data = new self();
        $data->ambience = $translation->ambience;
        $data->capacityNote = $translation->capacityNote;
        $data->partyNote = $translation->partyNote;
        $data->openingHours = $translation->openingHours;

        return $data;
    }

    /**
     * @return array{ambience: ?string, capacityNote: ?string, partyNote: ?string, openingHours: ?string}
     */
    public function toArray(): array
    {
        return [
            'ambience' => $this->ambience,
            'capacityNote' => $this->capacityNote,
            'partyNote' => $this->partyNote,
            'openingHours' => $this->openingHours,
        ];
    }
}
