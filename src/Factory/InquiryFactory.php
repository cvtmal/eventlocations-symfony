<?php

declare(strict_types=1);

namespace App\Factory;

use App\Inquiries\Entity\Inquiry;
use App\Inquiries\ValueObject\Occasion;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

use function Zenstruck\Foundry\lazy;

/**
 * The venue is created first and lazily: `Inquiry` copies its `number()`, which exists only
 * after the insert, so a nested factory would be too late.
 *
 * @extends PersistentObjectFactory<Inquiry>
 */
final class InquiryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Inquiry::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => lazy(static fn () => VenueFactory::new()->withContact()->create()),
            'venueSlug' => self::faker()->unique()->slug(3),
            'locale' => 'de',
            'occasion' => Occasion::Wedding,
            'guestCount' => self::faker()->numberBetween(20, 300),
            'eventDate' => null,
            'message' => self::faker()->realText(200),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'email' => self::faker()->safeEmail(),
            'phone' => null,
            'fingerprint' => self::faker()->sha256(),
            'createdAt' => new \DateTimeImmutable(),
        ];
    }
}
