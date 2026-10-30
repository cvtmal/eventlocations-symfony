<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Search\ReadModel\CardPhoto;
use App\Search\ReadModel\SimilarVenue;
use App\Search\ReadModel\SimilarVenuesQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Ähnliche Locations" of the venue detail page (DP-15), as an embedded fragment: the Venues
 * template `venues/detail/show.html.twig` renders it with
 * `render(controller('App\\Search\\Controller\\SimilarVenuesController', {venueId: venue.id}))`,
 * so no PHP class in Venues depends on Search (S2). It has no route on purpose: it is never
 * requested on its own. An empty result renders nothing (no heading).
 */
final class SimilarVenuesController extends AbstractController
{
    public function __construct(
        private readonly SimilarVenuesQuery $similarVenues,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, string $venueId): Response
    {
        $locale = $request->getLocale();
        $venues = $this->similarVenues->forVenue($venueId, $locale);
        if ([] === $venues) {
            return new Response('');
        }

        return $this->render('search/_similar_venues.html.twig', [
            'items' => array_map(fn (SimilarVenue $venue): array => [
                'venue' => $venue,
                'picture' => null === $venue->photo ? null : $this->picture($venue->photo, $venue->name, $locale),
            ], $venues),
        ]);
    }

    /**
     * CardPhoto → Picture (#6 recipe). Second lander of #7/#8: replace with #7's card_picture().
     */
    private function picture(CardPhoto $photo, string $name, string $locale): ?Picture
    {
        if (!$photo->hasVariants()) {
            return null;
        }
        \assert(null !== $photo->width && null !== $photo->height && null !== $photo->dominantColor && null !== $photo->variants);

        return new Picture(
            $photo->venueNumber,
            $photo->photoId,
            $photo->width,
            $photo->height,
            $photo->dominantColor,
            ImageVariants::fromArray($photo->variants),
            $photo->alt ?? $this->translator->trans('picture.alt_fallback', ['name' => $name, 'position' => $photo->position], 'media', $locale),
        );
    }
}
