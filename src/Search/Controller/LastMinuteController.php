<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Search\ReadModel\LastMinutePageQuery;
use App\Shared\Http\PublicCache;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page «Kurzfristig frei» (G14, D55): the live offers of Pro venues, each venue's card with its offers and a link to
 * its inquiry form. Cached until Zurich midnight (`PublicCache::listing()`), when the first offers run out; a publish
 * or an end purges the listings. Empty, it says so, links to the listing and is `noindex`.
 */
final class LastMinuteController extends AbstractController
{
    public function __construct(
        private readonly LastMinutePageQuery $offers,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: ['de' => '/kurzfristig-frei'], name: 'last_minute', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $response = $this->render('search/last_minute.html.twig', [
            'entries' => $this->offers->entries($request->getLocale()),
        ]);
        $response->setCache(PublicCache::listing($this->clock->now()));

        return $response;
    }
}
