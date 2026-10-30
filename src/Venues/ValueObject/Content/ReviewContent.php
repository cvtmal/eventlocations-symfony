<?php

declare(strict_types=1);

namespace App\Venues\ValueObject\Content;

use App\Venues\ValueObject\ReviewerRole;

/**
 * One review of venue.yaml.
 */
final readonly class ReviewContent
{
    /**
     * @param \DateTimeImmutable $month  the first day of the review's month
     * @param string             $locale the language of the text
     */
    public function __construct(
        public string $firstName,
        public ReviewerRole $role,
        public \DateTimeImmutable $month,
        public string $locale,
        public string $text,
    ) {
    }
}
