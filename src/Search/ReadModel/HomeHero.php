<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

/**
 * The hero photo of the home page (Q18) and the venue it belongs to.
 */
final readonly class HomeHero
{
    public function __construct(
        public CardPhoto $photo,
        public string $venueName,
    ) {
    }

    /**
     * Q18's fallback: the first Spotlight card with a photo.
     *
     * @param list<ListingCard> $cards
     */
    public static function fromCards(array $cards): ?self
    {
        foreach ($cards as $card) {
            if ([] !== $card->photos) {
                return new self($card->photos[0], $card->name);
            }
        }

        return null;
    }
}
