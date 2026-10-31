<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\Entity;

use App\Venues\Entity\VenueReview;
use App\Venues\ValueObject\ReviewerRole;
use PHPUnit\Framework\TestCase;

final class VenueReviewTest extends TestCase
{
    public function test_month_is_normalised_to_its_first_day(): void
    {
        $review = new VenueReview(VenueObjects::venue(), 'de', 'Anna', ReviewerRole::VisitorF, new \DateTimeImmutable('2026-05-17 14:30'), 'Schön.');

        self::assertSame('2026-05-01 00:00:00', $review->reviewedMonth()->format('Y-m-d H:i:s'));
    }

    public function test_revise_replaces_the_fields_and_keeps_the_language(): void
    {
        $review = new VenueReview(VenueObjects::venue(), 'de', 'Anna', ReviewerRole::VisitorF, new \DateTimeImmutable('2026-05-17'), 'Schön.');
        $before = $review->updatedAt();
        usleep(1);

        $review->revise('Beat', ReviewerRole::OrganiserM, new \DateTimeImmutable('2025-11-23 09:15'), 'Sehr schön.');

        self::assertSame(['Beat', ReviewerRole::OrganiserM, '2025-11-01 00:00:00', 'Sehr schön.', 'de'], [
            $review->firstName(), $review->role(), $review->reviewedMonth()->format('Y-m-d H:i:s'), $review->text(), $review->locale(),
        ]);
        self::assertGreaterThan($before, $review->updatedAt());
    }
}
