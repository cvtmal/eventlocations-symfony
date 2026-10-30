<?php

declare(strict_types=1);

namespace App\Search\Twig;

use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Search\ReadModel\CardPhoto;
use App\Search\ReadModel\SearchCriteria;
use App\Venues\ValueObject\RatingLabel;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Listing helpers for templates, which cannot build URLs from a SearchCriteria or construct
 * a Picture themselves:
 *
 * - `listing_path(criteria, page?)`: the canonical listing URL of a filter state (#6 toQuery()).
 * - `listing_path_without(criteria, params, value?)`: the same without whole params, or
 *   without one value of a list param (a chip's remove link). Always drops `page`.
 * - `card_picture(photo, venueName)`: the CardPhoto → Picture recipe of #6, null without variants.
 * - `rating_label(score)`: RatingLabel::fromScore() (Twig cannot call static enum methods).
 * - `venue_path(slug)`: the detail page's path (#8's `venue_show`).
 */
final class ListingExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AsTwigFunction('listing_path')]
    public function listingPath(SearchCriteria $criteria, ?int $page = null): string
    {
        return $this->urls->generate('listing', (null === $page ? $criteria : $criteria->withPage($page))->toQuery());
    }

    /**
     * @param string|list<string> $params
     */
    #[AsTwigFunction('listing_path_without')]
    public function listingPathWithout(SearchCriteria $criteria, string|array $params, ?string $value = null): string
    {
        $query = $criteria->toQuery();
        unset($query['page']);

        foreach ((array) $params as $param) {
            if (!isset($query[$param])) {
                continue;
            }
            if (null === $value) {
                unset($query[$param]);
                continue;
            }
            $current = $query[$param];
            if (\is_array($current)) {
                $rest = array_values(array_filter($current, static fn (string $item): bool => $item !== $value));
                if ([] === $rest) {
                    unset($query[$param]);
                } else {
                    $query[$param] = $rest;
                }
            } elseif ($current === $value) {
                unset($query[$param]);
            }
        }

        return $this->urls->generate('listing', $query);
    }

    #[AsTwigFunction('card_picture')]
    public function cardPicture(CardPhoto $photo, string $venueName): ?Picture
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
            $photo->alt ?? $this->translator->trans('picture.alt_fallback', ['name' => $venueName, 'position' => $photo->position], 'media'),
        );
    }

    #[AsTwigFunction('rating_label')]
    public function ratingLabel(float $score): ?RatingLabel
    {
        return RatingLabel::fromScore($score);
    }

    #[AsTwigFunction('venue_path')]
    public function venuePath(string $slug): string
    {
        return $this->urls->generate('venue_show', ['slug' => $slug]);
    }
}
