<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\ManagedReview;
use App\Venues\Command\AddVenueReview;
use App\Venues\Command\ChangeVenueReview;
use App\Venues\ValueObject\ReviewerRole;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A review (#25, CMS-8). The new form carries the review's `locale` (hidden, the default locale; the type
 * restricts it to the enabled locales); the edit form carries the row token instead (group `token`),
 * because a review's language does not change.
 */
final class ReviewData
{
    public const int FIRST_NAME_MAX = 50;

    public const int TEXT_MAX = 1000;

    /** `Y-m` from 1900 on: `ReviewInput::month()` is strict, and Postgres has no year 0. */
    public const string MONTH_PATTERN = '/\A(19|20)\d{2}-(0[1-9]|1[0-2])\z/';

    #[Assert\NotBlank(message: 'cms.venue.loaded_at.invalid', groups: ['token'])]
    #[Assert\Regex(pattern: '/\A\d{1,12}\z/', message: 'cms.venue.loaded_at.invalid', normalizer: 'mb_scrub', groups: ['token'])]
    public ?string $loadedAt = null;

    public ?string $locale = null;

    #[Assert\NotBlank(message: 'cms.review.first_name.required')]
    #[Assert\Length(max: self::FIRST_NAME_MAX, maxMessage: 'cms.venue.too_long')]
    public ?string $firstName = null;

    #[Assert\NotNull(message: 'cms.review.role.required')]
    public ?ReviewerRole $role = null;

    #[Assert\NotBlank(message: 'cms.review.month.required')]
    #[Assert\Regex(pattern: self::MONTH_PATTERN, message: 'cms.review.month.format', normalizer: 'mb_scrub')]
    public ?string $month = null;

    #[Assert\NotBlank(message: 'cms.review.text.required')]
    #[Assert\Length(max: self::TEXT_MAX, maxMessage: 'cms.venue.too_long')]
    public ?string $text = null;

    public static function new(string $locale): self
    {
        $data = new self();
        $data->locale = $locale;

        return $data;
    }

    public static function from(ManagedReview $review): self
    {
        $data = new self();
        $data->loadedAt = $review->token;
        $data->locale = $review->locale;
        $data->firstName = $review->firstName;
        $data->role = $review->role;
        $data->month = $review->month;
        $data->text = $review->text;

        return $data;
    }

    /**
     * Call only after the form validated.
     */
    public function toAddCommand(int $venueNumber): AddVenueReview
    {
        return new AddVenueReview($venueNumber, (string) $this->locale, (string) $this->firstName, $this->role()->value, (string) $this->month, (string) $this->text);
    }

    /**
     * Call only after the form validated.
     */
    public function toChangeCommand(int $venueNumber, string $reviewId): ChangeVenueReview
    {
        return new ChangeVenueReview($venueNumber, $reviewId, (string) $this->loadedAt, (string) $this->firstName, $this->role()->value, (string) $this->month, (string) $this->text);
    }

    private function role(): ReviewerRole
    {
        return $this->role ?? throw new \LogicException('Validate the form before building its command.');
    }
}
