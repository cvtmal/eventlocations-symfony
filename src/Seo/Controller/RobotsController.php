<?php

declare(strict_types=1);

namespace App\Seo\Controller;

use App\Shared\Http\PublicCache;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SEO-3: a route rather than a file, so the sitemap line carries the request's host. `/telefon`
 * is disallowed for its limiter too: a crawler that runs into 429s slows down everywhere.
 */
final class RobotsController extends AbstractController
{
    private const array DISALLOW = [
        '/_search/',
        '/merkliste',
        '/admin',
        '/konto',
        '/eventlokal/*/telefon',
        '/eventlokal/*/anfrage',
        // #47: a crawler that renders pages must not send the statistics beacon.
        '/_stats',
    ];

    public function __construct(
        // Pre-launch, temporary: true keeps Googlebot off the whole site; other crawlers are unaffected.
        #[Autowire('%seo.robots_block_google%')]
        private readonly bool $blockGoogle,
    ) {
    }

    #[Route('/robots.txt', name: 'robots', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    #[Cache(public: true, smaxage: PublicCache::PAGE_TTL, staleWhileRevalidate: PublicCache::STALE_TTL, staleIfError: PublicCache::STALE_TTL)]
    public function __invoke(): Response
    {
        // Googlebot obeys only its own group once one exists, so it never reads the `*` rules below.
        $lines = $this->blockGoogle ? ['User-agent: Googlebot', 'Disallow: /', ''] : [];
        $lines[] = 'User-agent: *';
        foreach (self::DISALLOW as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new Response(implode("\n", $lines)."\n", headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
