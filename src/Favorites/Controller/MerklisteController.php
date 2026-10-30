<?php

declare(strict_types=1);

namespace App\Favorites\Controller;

use App\Favorites\ValueObject\MerklisteCookie;
use App\Search\ReadModel\ListingCard;
use App\Search\ReadModel\ListingQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/merkliste` (MK-3): the saved venues in saved order. The only GET that reads the cookie, so it
 * is the no-JS source of truth, and never cached. It also prunes: garbage, duplicates and venues
 * that are gone leave the cookie, so the header count and "N Locations" cannot disagree.
 */
final class MerklisteController extends AbstractController
{
    public function __construct(private readonly ListingQuery $listing)
    {
    }

    #[Route(path: ['de' => '/merkliste'], name: 'merkliste', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $cookie = MerklisteCookie::fromRequest($request);
        $cards = $this->listing->cardsByNumbers($cookie->numbers(), $request->getLocale());
        $kept = $cookie->only(array_map(static fn (ListingCard $card): int => $card->number, $cards));

        $response = $this->render('favorites/merkliste.html.twig', [
            'cards' => $cards,
            'full' => $kept->isFull(),
            'max' => MerklisteCookie::MAX,
        ]);

        $received = $request->cookies->all()[MerklisteCookie::NAME] ?? null;
        if ($kept->value() !== (\is_string($received) ? $received : '')) {
            $response->headers->setCookie($kept->toCookie());
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
