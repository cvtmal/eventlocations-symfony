<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenuePhoto;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A venue-level photo by default (room NULL, position 1). The path is fictional; #5 brings
 * real media.
 *
 * @extends PersistentObjectFactory<VenuePhoto>
 */
final class VenuePhotoFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenuePhoto::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'room' => null,
            'position' => 1,
            'originalPath' => 'demo/'.self::faker()->unique()->slug(2).'.jpg',
        ];
    }
}
