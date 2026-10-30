<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenuePhotoTranslation;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VenuePhotoTranslation>
 */
final class VenuePhotoTranslationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenuePhotoTranslation::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'photo' => VenuePhotoFactory::new(),
            'locale' => 'de',
            'alt' => self::faker()->sentence(6),
        ];
    }
}
