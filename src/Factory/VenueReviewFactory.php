<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenueReview;
use App\Venues\ValueObject\ReviewerRole;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VenueReview>
 */
final class VenueReviewFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenueReview::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'locale' => 'de',
            'firstName' => self::faker()->firstName(),
            'role' => self::faker()->randomElement(ReviewerRole::cases()),
            'reviewedMonth' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-3 years', 'now')),
            'text' => self::faker()->realText(300),
        ];
    }
}
