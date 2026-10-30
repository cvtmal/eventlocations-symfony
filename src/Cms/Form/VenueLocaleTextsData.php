<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEditTranslation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Texte card's fields of one locale. Whether the summary is required is the type's option, since only
 * the default locale needs one.
 */
final class VenueLocaleTextsData
{
    #[Assert\Length(max: 200, maxMessage: 'cms.venue.too_long')]
    public ?string $summary = null;

    #[Assert\Length(max: 20000, maxMessage: 'cms.venue.too_long')]
    public ?string $description = null;

    #[Assert\Length(max: 160, maxMessage: 'cms.venue.too_long')]
    public ?string $metaDescription = null;

    #[Assert\Length(max: 250, maxMessage: 'cms.venue.too_long')]
    public ?string $parkingText = null;

    public static function from(?VenueEditTranslation $translation): self
    {
        $data = new self();
        $data->summary = $translation?->summary;
        $data->description = $translation?->description;
        $data->metaDescription = $translation?->metaDescription;
        $data->parkingText = $translation?->parkingText;

        return $data;
    }

    /**
     * @return array{summary: string, description: ?string, metaDescription: ?string, parkingText: ?string}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary ?? '',
            'description' => $this->description,
            'metaDescription' => $this->metaDescription,
            'parkingText' => $this->parkingText,
        ];
    }
}
