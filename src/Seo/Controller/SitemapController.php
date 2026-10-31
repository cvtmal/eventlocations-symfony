<?php

declare(strict_types=1);

namespace App\Seo\Controller;

use App\Seo\PagesSitemap;
use App\Seo\ValueObject\SitemapUrl;
use App\Shared\Http\PublicCache;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ReadModel\SitemapVenue;
use App\Venues\ReadModel\VenueSitemapQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SEO-3: `/sitemap.xml` is always an index, so the URL shape does not change at the 1,001st
 * venue. Its children sit at the root, because a sitemap lists only URLs under its own directory.
 * Only `loc`, `lastmod` and `image:loc`: Google ignores `priority` and `changefreq`.
 */
final class SitemapController extends AbstractController
{
    private const array XML = ['Content-Type' => 'application/xml; charset=UTF-8'];

    public function __construct(
        private readonly VenueSitemapQuery $venues,
        private readonly PagesSitemap $pages,
        private readonly UrlHelper $urlHelper,
    ) {
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    #[Cache(public: true, smaxage: PublicCache::PAGE_TTL, staleWhileRevalidate: PublicCache::STALE_TTL, staleIfError: PublicCache::STALE_TTL)]
    public function index(): Response
    {
        return $this->render('seo/sitemap_index.xml.twig', [
            'files' => $this->venues->files(),
            'pages_lastmod' => $this->venues->newestChange(),
        ], new Response(headers: self::XML));
    }

    #[Route('/sitemap-pages.xml', name: 'sitemap_pages', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    #[Cache(public: true, smaxage: PublicCache::PAGE_TTL, staleWhileRevalidate: PublicCache::STALE_TTL, staleIfError: PublicCache::STALE_TTL)]
    public function pages(): Response
    {
        return $this->render('seo/sitemap_urlset.xml.twig', ['urls' => $this->pages->urls()], new Response(headers: self::XML));
    }

    #[Route('/sitemap-venues-{n}.xml', name: 'sitemap_venues', requirements: ['n' => '[1-9][0-9]{0,5}'], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    #[Cache(public: true, smaxage: PublicCache::PAGE_TTL, staleWhileRevalidate: PublicCache::STALE_TTL, staleIfError: PublicCache::STALE_TTL)]
    public function venues(int $n): Response
    {
        $venues = $this->venues->page($n);
        if ([] === $venues) {
            throw $this->createNotFoundException();
        }

        return $this->render('seo/sitemap_urlset.xml.twig', [
            'urls' => array_map($this->url(...), $venues),
        ], new Response(headers: self::XML));
    }

    private function url(SitemapVenue $venue): SitemapUrl
    {
        return new SitemapUrl(
            $this->generateUrl('venue_show', ['slug' => $venue->slug], UrlGeneratorInterface::ABSOLUTE_URL),
            $venue->lastmod,
            array_map($this->urlHelper->getAbsoluteUrl(...), $venue->imageUrls),
        );
    }
}
