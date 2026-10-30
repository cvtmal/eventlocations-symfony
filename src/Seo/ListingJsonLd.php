<?php

declare(strict_types=1);

namespace App\Seo;

use App\Search\ReadModel\ListingCard;
use App\Search\ReadModel\ListingPage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SEO-2: the ItemList of one listing page. Positions continue across pages.
 */
final class ListingJsonLd
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>|null null for a page without cards
     */
    public function build(ListingPage $page): ?array
    {
        if ([] === $page->cards) {
            return null;
        }

        $offset = ($page->page - 1) * $page->perPage;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => array_map(fn (ListingCard $card, int $index): array => [
                '@type' => 'ListItem',
                'position' => $offset + $index + 1,
                'url' => $this->urls->generate('venue_show', ['slug' => $card->slug], UrlGeneratorInterface::ABSOLUTE_URL),
                'name' => $card->name,
            ], $page->cards, array_keys($page->cards)),
        ];
    }
}
