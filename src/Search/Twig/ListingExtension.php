<?php

declare(strict_types=1);

namespace App\Search\Twig;

use App\Geo\ReadModel\PlaceResolver;
use App\Geo\ReadModel\ResolvedPlace;
use App\Media\ValueObject\ImageVariants;
use App\Media\ValueObject\Picture;
use App\Search\LandingCopy;
use App\Search\LandingPaths;
use App\Search\ReadModel\CardPhoto;
use App\Search\ReadModel\FilterDataQuery;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ValueObject\LandingType;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Listing helpers for templates, which cannot build URLs from a SearchCriteria or construct
 * a Picture themselves:
 *
 * - `listing_path(criteria, page?)`: the canonical listing URL of a filter state (#6 toQuery()),
 *   on its landing page's path when it has one (#17 LandingPaths).
 * - `listing_path_without(criteria, params, value?)`: the same without whole params, or
 *   without one value of a list param (a chip's remove link). Always drops `page`.
 * - `listing_form(criteria, params)`: an overlay GET form's `action` (the landing path of the
 *   other filters), the `except` list for `_hidden_state` (its params plus the path's facets)
 *   and its live `count` URL, which carries those facets and ends in `?` or `&` (live-count.js).
 * - `landing_canton_paths(criteria?)`: canton code → path of every live canton page (the region form's map),
 *   or of the canton's event page when the criteria hold exactly one event type with one there (F8).
 * - `landing_heading(landing)`, `landing_intro(landing)`: a landing page's h1 and catalogue intro (LandingCopy).
 * - `landing_links(landing)`: "Andere suchten auch nach", live landing pages only.
 * - `landing_breadcrumb(landing)`: a landing page's breadcrumb items, absolute URLs, the last without one.
 * - `card_picture(photo, venueName)`: the CardPhoto → Picture recipe of #6, null without variants.
 * - `venue_path(slug)`: the detail page's path (#8's `venue_show`).
 * - `selected_place(criteria)`: the filter's `place` resolved to its town, null without a place or for an unknown one.
 * - `filter_has_data(filter)`: whether a published venue carries the filter's data (FilterDataQuery).
 * - `filter_terms(group, criteria)`: the group's codes with data or active, in vocabulary order.
 */
final class ListingExtension
{
    /** "Andere suchten auch nach" shows this many canton pages (#7's limit). */
    private const RELATED_CANTONS = 9;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly PlaceResolver $places,
        private readonly FilterDataQuery $filterData,
        private readonly LandingPages $landings,
        private readonly LandingPaths $paths,
        private readonly LandingCopy $copy,
    ) {
    }

    #[AsTwigFunction('landing_heading')]
    public function landingHeading(LandingPage $landing): string
    {
        return $this->copy->heading($landing);
    }

    #[AsTwigFunction('landing_intro')]
    public function landingIntro(LandingPage $landing): string
    {
        return $this->copy->intro($landing);
    }

    #[AsTwigFunction('listing_path')]
    public function listingPath(SearchCriteria $criteria, ?int $page = null): string
    {
        return $this->paths->url((null === $page ? $criteria : $criteria->withPage($page))->toQuery());
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

        return $this->paths->url($query);
    }

    /**
     * @param list<string> $params the form's own parameters
     *
     * @return array{action: string, except: list<string>, count: string}
     */
    #[AsTwigFunction('listing_form')]
    public function listingForm(SearchCriteria $criteria, array $params): array
    {
        $query = array_diff_key($criteria->toQuery(), array_flip([...$params, 'page']));
        [$landing] = $this->paths->locate($query);

        $count = $this->urls->generate('_search_count', $landing?->facetQuery() ?? []);

        return [
            'action' => $this->paths->path($landing),
            'except' => array_values(array_unique([...$params, ...($landing?->consumes() ?? [])])),
            'count' => $count.(str_contains($count, '?') ? '&' : '?'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[AsTwigFunction('landing_canton_paths')]
    public function landingCantonPaths(?SearchCriteria $criteria = null): array
    {
        // F8: with exactly one event type chosen, a canton goes to its event page in that canton.
        $events = $criteria?->termCodes(TermGroup::SuitableFor) ?? [];
        $paths = [];
        foreach ($this->landings->cantons() as $landing) {
            $event = 1 === \count($events) && null !== $landing->canton ? $this->landings->event($events[0], $landing->canton) : null;
            $paths[(string) $landing->canton?->value] = $this->paths->path($event ?? $landing);
        }

        return $paths;
    }

    /**
     * Pages related to the current one first, then the top canton pages, the event type pages and
     * the building pages (LP-5). A canton page puts its town and event pages first, a town page its
     * sibling towns, its canton and the canton's event pages, an event page its cantons, an event
     * page in a canton the canton's other event pages, the event page without canton and the
     * canton page. Each page once, the current page never.
     *
     * @return list<array{label: string, href: string}>
     */
    #[AsTwigFunction('landing_links')]
    public function landingLinks(?LandingPage $current = null): array
    {
        $canton = null === $current?->canton ? null : $this->landings->canton($current->canton);
        $first = match ($current?->type) {
            LandingType::Canton => [...$this->landings->towns($current->canton), ...$this->landings->eventCantons($current->canton)],
            LandingType::Town => [...$this->landings->towns($current->canton), $canton, ...$this->landings->eventCantons($current->canton)],
            LandingType::Event => null === $current->canton
                ? array_filter($this->landings->eventCantons(), static fn (LandingPage $page): bool => $page->event === $current->event)
                : [...$this->landings->eventCantons($current->canton), $this->landings->event((string) $current->event), $canton],
            default => [],
        };
        $cantons = \array_slice(array_values(array_filter(
            $this->landings->cantons(),
            static fn (LandingPage $page): bool => !\in_array($page, $first, true) && $page !== $current,
        )), 0, self::RELATED_CANTONS);

        $links = [];
        $seen = [];
        foreach ([...$first, ...$cantons, ...$this->landings->events(), ...$this->landings->buildings()] as $page) {
            if (null !== $page && $page !== $current && !\in_array($page, $seen, true)) {
                $seen[] = $page;
                $links[] = ['label' => $this->copy->related($page), 'href' => $this->paths->path($page)];
            }
        }

        return $links;
    }

    /**
     * @return list<array{name: string, url?: string}>
     */
    #[AsTwigFunction('landing_breadcrumb')]
    public function landingBreadcrumb(?LandingPage $landing): array
    {
        if (null === $landing) {
            return [];
        }
        $items = [['name' => $this->translator->trans('breadcrumb.listing', [], 'seo'), 'url' => $this->paths->path(null, [], UrlGeneratorInterface::ABSOLUTE_URL)]];
        if (\in_array($landing->type, [LandingType::Town, LandingType::Event], true) && null !== $landing->canton && null !== $canton = $this->landings->canton($landing->canton)) {
            $items[] = ['name' => $this->copy->breadcrumb($canton), 'url' => $this->paths->path($canton, [], UrlGeneratorInterface::ABSOLUTE_URL)];
        }
        $items[] = ['name' => $this->copy->breadcrumb($landing)];

        return $items;
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

    #[AsTwigFunction('venue_path')]
    public function venuePath(string $slug): string
    {
        return $this->urls->generate('venue_show', ['slug' => $slug]);
    }

    #[AsTwigFunction('selected_place')]
    public function selectedPlace(SearchCriteria $criteria): ?ResolvedPlace
    {
        return null === $criteria->place ? null : $this->places->resolve($criteria->place);
    }

    #[AsTwigFunction('filter_has_data')]
    public function filterHasData(string $filter): bool
    {
        return $this->filterData->has($filter);
    }

    /**
     * @return list<string>
     */
    #[AsTwigFunction('filter_terms')]
    public function filterTerms(TermGroup $group, SearchCriteria $criteria): array
    {
        return $this->filterData->terms($group, $criteria->termCodes($group));
    }
}
