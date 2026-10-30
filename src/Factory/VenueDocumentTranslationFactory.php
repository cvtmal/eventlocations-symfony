<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenueDocumentTranslation;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<VenueDocumentTranslation>
 */
final class VenueDocumentTranslationFactory extends PersistentObjectFactory
{
    private const TITLES = ['Preisliste', 'Bankettmappe', 'Raumplan', 'Menüvorschläge', 'Hausordnung'];

    public static function class(): string
    {
        return VenueDocumentTranslation::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'document' => VenueDocumentFactory::new(),
            'locale' => 'de',
            'title' => self::faker()->randomElement(self::TITLES),
        ];
    }
}
