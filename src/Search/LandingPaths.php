<?php

declare(strict_types=1);

namespace App\Search;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ValueObject\LandingType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The path-facet rule (#17): which landing page a filter state belongs on. A town page when
 * `place` is a town with a page, `radius` is 0 and `canton` is absent or the town's; else, when
 * `event` is exactly one code, its page in the `canton` (F8); else the canton page; else the
 * event page without a canton for exactly one `event` code; else the building page when
 * `building` is exactly one code with a page; else the plain listing. The path's facets leave the query, every other parameter stays in
 * SearchCriteria::toQuery() order. Every listing URL goes through here, so a filter state
 * has one URL.
 */
final class LandingPaths
{
    public function __construct(
        private readonly LandingPages $pages,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, string|list<string>> $query a SearchCriteria::toQuery() array
     *
     * @return array{0: LandingPage|null, 1: array<string, string|list<string>>} the page and the query it leaves
     */
    public function locate(array $query): array
    {
        $page = $this->townPage($query)
            ?? $this->eventPage($query, true)
            ?? $this->cantonPage($query)
            ?? $this->eventPage($query, false)
            ?? $this->buildingPage($query);
        if (null === $page) {
            return [null, $query];
        }

        foreach ($page->consumes() as $param) {
            unset($query[$param]);
        }

        return [$page, $query];
    }

    /**
     * @param array<string, string|list<string>> $query a SearchCriteria::toQuery() array
     */
    public function url(array $query, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        [$page, $rest] = $this->locate($query);

        return $this->path($page, $rest, $referenceType);
    }

    /**
     * The URL of a landing page (null: the plain listing) with further query parameters.
     *
     * @param array<string, string|list<string>> $rest parameters the page's path does not hold
     */
    public function path(?LandingPage $page, array $rest = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return match ($page?->type) {
            null => $this->urls->generate('listing', $rest, $referenceType),
            LandingType::Town => $this->urls->generate('listing_town', ['canton' => $page->cantonSlug, 'town' => $page->slug] + $rest, $referenceType),
            LandingType::Event => null === $page->canton
                ? $this->urls->generate('listing_landing', ['slug' => $page->slug] + $rest, $referenceType)
                : $this->urls->generate('listing_town', ['canton' => $page->cantonSlug, 'town' => $page->slug] + $rest, $referenceType),
            LandingType::Canton, LandingType::Building => $this->urls->generate('listing_landing', ['slug' => $page->slug] + $rest, $referenceType),
        };
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function townPage(array $query): ?LandingPage
    {
        if (!\is_string($query['place'] ?? null) || '0' !== ($query['radius'] ?? null)) {
            return null;
        }
        $page = $this->pages->town($query['place']);
        $canton = $query['canton'] ?? null;

        return null !== $page && (null === $canton || $canton === $page->canton?->value) ? $page : null;
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function cantonPage(array $query): ?LandingPage
    {
        $canton = $query['canton'] ?? null;
        foreach (\is_string($canton) ? $this->pages->cantons() : [] as $page) {
            if ($page->canton?->value === $canton) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The page of exactly one `event` code: in the query's canton (`$inCanton`) or without one.
     *
     * @param array<string, string|list<string>> $query
     */
    private function eventPage(array $query, bool $inCanton): ?LandingPage
    {
        $event = $query['event'] ?? null;
        if (!\is_array($event) || 1 !== \count($event)) {
            return null;
        }
        if (!$inCanton) {
            return $this->pages->event($event[0]);
        }
        $canton = \is_string($query['canton'] ?? null) ? Canton::tryFrom($query['canton']) : null;

        return null === $canton ? null : $this->pages->event($event[0], $canton);
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function buildingPage(array $query): ?LandingPage
    {
        $building = $query['building'] ?? null;

        return \is_array($building) && 1 === \count($building) ? $this->pages->building($building[0]) : null;
    }
}
