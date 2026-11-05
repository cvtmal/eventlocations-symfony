<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\ChangeVenueGooglePlace;
use App\Venues\ValueObject\GooglePlaceId;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Google-Bewertung (#53, D4, D6): the venue's Google place ID and the switch that hides its Google rating. The
 * pattern is `chk_venues_google_place_id`'s; `TrimListener` has trimmed the input, `mb_scrub` keeps invalid UTF-8
 * out of the message's `{{ value }}`. The handler clears the switch when the ID is cleared.
 */
final class VenueGoogleData implements VenueCardData
{
    use CarriesLoadedAt;

    #[Assert\Length(max: 255, maxMessage: 'cms.venue.too_long')]
    #[Assert\Regex(pattern: GooglePlaceId::PATTERN, message: 'cms.venue.google.place_id_format', normalizer: 'mb_scrub')]
    public ?string $placeId = null;

    public bool $ratingHidden = false;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->placeId = $edit->googlePlaceId;
        $data->ratingHidden = $edit->googleRatingHidden;

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueGooglePlace
    {
        return new ChangeVenueGooglePlace($venueNumber, $this->token(), $changedFields, $this->placeId, $this->ratingHidden);
    }
}
