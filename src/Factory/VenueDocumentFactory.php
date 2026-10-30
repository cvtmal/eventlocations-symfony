<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenueDocument;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * The path is fictional (no file exists); position defaults to 1.
 *
 * @extends PersistentObjectFactory<VenueDocument>
 */
final class VenueDocumentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenueDocument::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'position' => 1,
            'path' => 'demo/'.self::faker()->unique()->slug(2).'.pdf',
            'sizeBytes' => self::faker()->numberBetween(20_000, 3_000_000),
        ];
    }
}
