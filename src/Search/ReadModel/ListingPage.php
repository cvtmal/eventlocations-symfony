<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

/**
 * One page of the listing plus the total match count. A page beyond lastPage has no cards
 * (the listing page answers 404 for it).
 */
final readonly class ListingPage
{
    /**
     * @param list<ListingCard> $cards
     */
    private function __construct(
        public array $cards,
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
    ) {
    }

    /**
     * @param list<ListingCard> $cards
     */
    public static function of(array $cards, int $total, int $page, int $perPage): self
    {
        return new self($cards, $total, $page, $perPage, max(1, (int) ceil($total / $perPage)));
    }
}
