<?php

declare(strict_types=1);

namespace App\Seo\Controller;

use App\Search\ValueObject\LandingType;
use App\Seo\Extension\LandingPageCatalogue;
use App\Seo\MarkdownText;
use App\Shared\Http\PublicCache;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\ValueObject\LandingPageEntry;
use App\Venues\ReadModel\VenueSitemapQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/llms.txt` (llmstxt.org): the site's summary and every page a language model should read,
 * each venue as its Markdown view. Venues are the sitemap's published venues and landing pages
 * the live ones (above `%landing.min_venues%`), so every link answers 200. Cached like the
 * sitemaps; every purge empties the whole store, so it needs no purge of its own.
 */
final class LlmsTxtController extends AbstractController
{
    public function __construct(
        private readonly VenueSitemapQuery $venues,
        private readonly LandingPageCatalogue $landingPages,
        #[Autowire('%site.name%')]
        private readonly string $siteName,
    ) {
    }

    #[Route('/llms.txt', name: 'llms_txt', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    #[Cache(public: true, smaxage: PublicCache::PAGE_TTL, staleWhileRevalidate: PublicCache::STALE_TTL, staleIfError: PublicCache::STALE_TTL)]
    public function __invoke(): Response
    {
        $live = array_values(array_filter(
            $this->landingPages->pages(),
            static fn (LandingPageEntry $entry): bool => $entry->passesThreshold && $entry->indexable && null !== $entry->url,
        ));

        $body = $this->renderView('seo/llms.txt.twig', [
            'site_name' => $this->siteName,
            'cantons' => self::ofType($live, LandingType::Canton),
            'towns' => self::ofType($live, LandingType::Town),
            'buildings' => self::ofType($live, LandingType::Building),
            'events' => self::ofType($live, LandingType::Event),
            'venues' => $this->venues->index(),
        ]);

        return new Response(MarkdownText::tidy($body), headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @param list<LandingPageEntry> $entries
     *
     * @return list<LandingPageEntry>
     */
    private static function ofType(array $entries, LandingType $type): array
    {
        return array_values(array_filter($entries, static fn (LandingPageEntry $entry): bool => $type->value === $entry->type));
    }
}
