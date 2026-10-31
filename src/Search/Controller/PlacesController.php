<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Geo\ReadModel\PlaceLookup;
use App\Geo\ReadModel\PlaceSuggestion;
use App\Geo\ValueObject\Canton;
use App\Geo\ValueObject\PlaceType;
use App\Shared\Http\PublicCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ort/PLZ autocomplete: `GET /_search/places?q=` → `{"places": [...]}` with entries
 * `{"type":"canton","canton":"zh","label":"Zürich"}` or
 * `{"type":"town","id":"…","name":"Stäfa","zip":"8712","canton":"zh","lat":47.24,"lng":8.72}`.
 * Unlocalized, noindex, no session, publicly cacheable (towns change only on import).
 */
final class PlacesController extends AbstractController
{
    public function __construct(
        private readonly PlaceLookup $placeLookup,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/_search/places', name: '_search_places', methods: ['GET'])]
    #[Cache(maxage: PublicCache::PLACES_BROWSER_TTL, smaxage: PublicCache::PAGE_TTL, public: true)]
    public function __invoke(Request $request): JsonResponse
    {
        // all() never throws; get('q') would answer ?q[]=x with a 400.
        $q = $request->query->all()['q'] ?? '';
        $suggestions = \is_string($q) ? $this->placeLookup->suggest($q) : [];

        return new JsonResponse(
            ['places' => array_map($this->entry(...), $suggestions)],
            headers: ['X-Robots-Tag' => 'noindex'],
        );
    }

    /**
     * @return array<string, string|float|null>
     */
    private function entry(PlaceSuggestion $suggestion): array
    {
        if (PlaceType::Canton === $suggestion->type) {
            return [
                'type' => 'canton',
                'canton' => $suggestion->cantonCode,
                'label' => Canton::from($suggestion->cantonCode)->trans($this->translator),
            ];
        }

        return [
            'type' => 'town',
            'id' => $suggestion->townId,
            'name' => $suggestion->name,
            'zip' => $suggestion->zip,
            'canton' => $suggestion->cantonCode,
            'lat' => $suggestion->lat,
            'lng' => $suggestion->lng,
        ];
    }
}
