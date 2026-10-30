<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\RateVenue;
use App\Venues\ValueObject\RatingHighlight;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Bewertung (D17, `chk_venues_rating`): score and count both or neither, a score of 0–10 with one decimal,
 * at most three highlights, and highlights only with a rating.
 */
final class VenueRatingData implements VenueCardData
{
    use CarriesLoadedAt;

    #[Assert\Sequentially(constraints: [
        new Assert\Regex(pattern: '/\A\d{1,2}(\.\d)?\z/', message: 'cms.venue.rating.format', normalizer: 'mb_scrub'),
        new Assert\Range(notInRangeMessage: 'cms.venue.rating.range', min: 0, max: 10),
    ])]
    public ?string $score = null;

    #[Assert\Range(notInRangeMessage: 'cms.venue.rating.count_range', min: 0, max: 2147483647)]
    public ?int $count = null;

    /** @var list<RatingHighlight> */
    #[Assert\Count(max: 3, maxMessage: 'cms.venue.rating.highlights_max')]
    public array $highlights = [];

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->score = $edit->ratingScore;
        $data->count = $edit->ratingCount;
        $data->highlights = $edit->highlights;

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): RateVenue
    {
        return new RateVenue($venueNumber, $this->token(), $changedFields, $this->score, $this->count, $this->highlights);
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->score && null === $this->count) {
            $context->buildViolation('cms.venue.rating.both')->atPath('count')->addViolation();
        }
        if (null === $this->score && null !== $this->count) {
            $context->buildViolation('cms.venue.rating.both')->atPath('score')->addViolation();
        }
        if ([] !== $this->highlights && null === $this->score && null === $this->count) {
            $context->buildViolation('cms.venue.rating.highlights_need_rating')->atPath('highlights')->addViolation();
        }
    }
}
