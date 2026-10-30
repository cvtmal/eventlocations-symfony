<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenueSlugHistory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VenueSlugHistory>
 */
final class VenueSlugHistoryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenueSlugHistory::class;
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
        ];
    }
}
