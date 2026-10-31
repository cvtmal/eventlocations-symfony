<?php

declare(strict_types=1);

namespace App\Venues;

use App\Venues\ValueObject\ReviewerRole;

/**
 * The scalar review fields of AddVenueReview and ChangeVenueReview as the entity takes them
 * (#25, CMS-8). The form restricts both, so a bad value is an \InvalidArgumentException.
 */
final class ReviewInput
{
    public static function role(string $role): ReviewerRole
    {
        return ReviewerRole::tryFrom($role) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a reviewer role.', $role));
    }

    /**
     * `Y-m` ("2026-09") → the first of that month, as `venue_reviews.reviewed_month` holds it.
     */
    public static function month(string $month): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (false === $date || $date->format('Y-m') !== $month) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a month (Y-m).', $month));
        }

        return $date;
    }
}
