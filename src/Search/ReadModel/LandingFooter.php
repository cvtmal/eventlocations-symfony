<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The footer's canton links, kept in `cache.landing_pages` so every page, the error pages
 * included, renders them without a query (#16's ErrorPagesQueryFreeTest). LandingPages writes
 * the item when its canton pages change; it never expires. A cold pool renders nothing.
 */
final class LandingFooter
{
    public const KEY = 'footer_cantons';

    public function __construct(
        #[Autowire(service: 'cache.landing_pages')]
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * @param list<LandingPage> $cantons
     */
    public function store(array $cantons): void
    {
        $value = array_map(
            static fn (LandingPage $page): array => ['canton' => $page->canton?->value, 'slug' => $page->slug],
            $cantons,
        );

        try {
            $item = $this->pool->getItem(self::KEY);
            if ($item->isHit() && $item->get() === $value) {
                return;
            }
            $this->pool->save($item->set($value));
        } catch (\Throwable) {
            // A cache failure must never fail a listing page; the footer shows the old list or none.
        }
    }

    /**
     * @return list<array{canton: Canton, slug: string}>
     */
    public function cantons(): array
    {
        try {
            $item = $this->pool->getItem(self::KEY);
            $stored = $item->isHit() ? $item->get() : [];
        } catch (\Throwable) {
            return [];
        }

        $cantons = [];
        foreach (\is_array($stored) ? $stored : [] as $entry) {
            $canton = \is_array($entry) && \is_string($entry['canton'] ?? null) ? Canton::tryFrom($entry['canton']) : null;
            if (null !== $canton && \is_string($entry['slug'] ?? null)) {
                $cantons[] = ['canton' => $canton, 'slug' => $entry['slug']];
            }
        }

        return $cantons;
    }
}
