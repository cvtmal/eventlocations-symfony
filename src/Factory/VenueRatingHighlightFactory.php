<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenueRatingHighlight;
use App\Venues\ValueObject\RatingHighlight;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Position defaults to 1; pass `position` for a venue's second and third highlight.
 *
 * @extends PersistentObjectFactory<VenueRatingHighlight>
 */
final class VenueRatingHighlightFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenueRatingHighlight::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'position' => 1,
            'code' => self::faker()->randomElement(RatingHighlight::cases()),
        ];
    }
}
