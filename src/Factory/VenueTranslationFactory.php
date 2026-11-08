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

    public const string DESCRIPTION = 'Ein heller Saal für Seminare und Firmenanlässe, mit Foyer für den Apéro und Technik für Präsentationen.';

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
            // G12: a written description, so a factory entry passes VenueIndexGate; pass null for one without text.
            // A constant, not faker: DemoStory's data must not shift with the shared faker.
            'description' => self::DESCRIPTION,
        ];
    }
}
