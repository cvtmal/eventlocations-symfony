<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\ValueObject\ReviewerRole;

/**
 * One review on the reviews page (#25, CMS-8). Score, count and highlights are the venue's
 * (#24's Bewertung card, D17), not the review's.
 */
final readonly class ManagedReview
{
    /**
     * @param string $month `Y-m`
     * @param string $token the row token (`RowTokens::sql()` of `venue_reviews.updated_at`)
     */
    public function __construct(
        public string $id,
        public string $locale,
        public string $firstName,
        public ReviewerRole $role,
        public string $month,
        public string $text,
        public string $token,
    ) {
    }
}
