<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenueTranslation;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VenueTranslation>
 */
final class VenueTranslationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenueTranslation::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'locale' => 'de',
            'slug' => self::faker()->unique()->slug(3),
            'summary' => self::faker()->realText(190),
        ];
    }
}
