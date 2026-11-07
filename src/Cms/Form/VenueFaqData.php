<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Cms\ValueObject\TriState;
use App\Venues\Command\AnswerVenueFaq;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Häufige Fragen: daylight, "unknown" when unanswered (catering is the `catering` term group, D33).
 */
final class VenueFaqData implements VenueCardData
{
    use CarriesLoadedAt;

    #[Assert\NotNull(message: 'cms.venue.choice.required')]
    public ?TriState $daylight = TriState::Unknown;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->daylight = TriState::fromBool($edit->daylight);

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): AnswerVenueFaq
    {
        return new AnswerVenueFaq($venueNumber, $this->token(), $changedFields, ($this->daylight ?? TriState::Unknown)->toBool());
    }
}
