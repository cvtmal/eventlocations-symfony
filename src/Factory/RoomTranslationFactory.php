<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\RoomTranslation;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<RoomTranslation>
 */
final class RoomTranslationFactory extends PersistentObjectFactory
{
    private const NAMES = ['Saal', 'Foyer', 'Gewölbekeller', 'Terrassenzimmer', 'Salon', 'Scheune', 'Wintergarten'];

    public static function class(): string
    {
        return RoomTranslation::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'room' => RoomFactory::new(),
            'locale' => 'de',
            'name' => self::faker()->randomElement(self::NAMES),
            'description' => null,
        ];
    }
}
