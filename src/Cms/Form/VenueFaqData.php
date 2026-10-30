<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Cms\ValueObject\TriState;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\ValueObject\OwnCatering;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Häufige Fragen: own catering and daylight, both "unknown" when unanswered.
 */
final class VenueFaqData implements VenueCardData
{
    use CarriesLoadedAt;

    public ?OwnCatering $ownCatering = null;

    #[Assert\NotNull(message: 'cms.venue.choice.required')]
    public ?TriState $daylight = TriState::Unknown;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->ownCatering = $edit->ownCatering;
        $data->daylight = TriState::fromBool($edit->daylight);

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): AnswerVenueFaq
    {
        return new AnswerVenueFaq($venueNumber, $this->token(), $changedFields, $this->ownCatering, ($this->daylight ?? TriState::Unknown)->toBool());
    }
}
