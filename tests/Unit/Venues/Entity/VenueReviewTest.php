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
}
