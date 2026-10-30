<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\ReviewerRole;

/**
 * One quote of the reviews section (DP-14): "{text}" — "{first name} - {role}, {Monat Jahr}".
 */
final readonly class DetailReview
{
    public function __construct(
        public string $firstName,
        public ReviewerRole $role,
        public \DateTimeImmutable $month,
        public string $text,
    ) {
    }
}
