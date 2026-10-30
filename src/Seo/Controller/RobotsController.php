<?php

declare(strict_types=1);

namespace App\Seo\Controller;

use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        '/hochzeitslocation/*/telefon',
        '/hochzeitslocation/*/anfrage',
    ];

    #[Route('/robots.txt', name: 'robots', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    #[Cache(public: true, smaxage: 600, staleWhileRevalidate: 86400)]
    public function __invoke(): Response
    {
        $lines = ['User-agent: *'];
        foreach (self::DISALLOW as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->generateUrl('sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return new Response(implode("\n", $lines)."\n", headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
