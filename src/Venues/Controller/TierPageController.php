<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Shared\Http\PublicCache;
use App\Venues\ReadModel\TierMatrixQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Location eintragen» (G13): what a venue gets for free and with Pro, from the `tiers` rows, the «Top-Eintrag»
 * explanation, the FAQ and the way to register or claim. Cached like every public page; a staff edit of a tier or of
 * the FAQ purges the whole store (`ReviseTier`, `SaveTextBlock`).
 */
final class TierPageController extends AbstractController
{
    public function __construct(
        private readonly TierMatrixQuery $matrix,
    ) {
    }

    #[Route(path: ['de' => '/location-eintragen'], name: 'tier_page', methods: ['GET'])]
    #[Cache(public: true, smaxage: PublicCache::PAGE_TTL, staleWhileRevalidate: PublicCache::STALE_TTL, staleIfError: PublicCache::STALE_TTL)]
    public function __invoke(): Response
    {
        return $this->render('venues/tiers/show.html.twig', [
            'matrix' => $this->matrix->matrix(),
        ]);
    }
}
