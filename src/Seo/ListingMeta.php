<?php

declare(strict_types=1);

namespace App\Seo;

use App\Search\LandingPaths;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\ListingPage;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ValueObject\LandingType;
use App\Seo\ValueObject\PageMeta;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEO-1 and SEO-4 for the listing and its landing pages (#17), driven by the parsed criteria.
 * The canonical is the path-facet URL of the criteria (LandingPaths), so `?canton=zh`
 * canonicalises to `/hochzeitslocations/zuerich`. A page is filtered, so `noindex, follow` with
 * a page-1 canonical, when a parameter besides `page` is left after the path took its facets,
 * or when the facet came from the query rather than the route (P5). An unfiltered page N
 * canonicalises to itself. Unknown parameters and empty values never survive SearchCriteria,
 * so they count as the clean page.
 */
final class ListingMeta
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LandingPaths $paths,
        private readonly TitleChain $titles,
        #[Autowire('%site.name%')]
        private readonly string $site,
    ) {
    }

    public function for(SearchCriteria $criteria, ListingPage $page, ?LandingPage $landing = null): PageMeta
    {
        [$located, $rest] = $this->paths->locate($criteria->withPage(1)->toQuery());
        // LandingPages memoizes its pages, so the route's page and the located one are the same instance.
        $filtered = [] !== $rest || $located !== $landing;
        $numbered = !$filtered && $page->page > 1;

        if (null === $located) {
            $title = $this->translator->trans($numbered ? 'listing.title_page' : 'listing.title', ['site' => $this->site, 'page' => (string) $page->page], 'seo');
            $description = $this->translator->trans($numbered ? 'listing.description_page' : 'listing.description', ['page' => (string) $page->page], 'seo');
        } else {
            $title = $this->landingTitle($located, $numbered, $page->page);
            $description = MetaDescription::fit($this->translator->trans(
                'landing.'.$located->type->value.'.description',
                $this->parameters($located) + ['count' => $located->venues],
                'seo',
            ));
        }

        return new PageMeta(
            $title,
            $description,
            $this->paths->path($located, $numbered ? ['page' => (string) $page->page] : [], UrlGeneratorInterface::ABSOLUTE_URL),
            $filtered,
            'website',
        );
    }

    private function landingTitle(LandingPage $landing, bool $numbered, int $page): string
    {
        $prefix = 'landing.'.$landing->type->value.'.';
        $parameters = $this->parameters($landing) + ['count' => (string) $landing->venues, 'page' => (string) $page];
        $keys = match (true) {
            $numbered && LandingType::Building === $landing->type => ['title_page', 'title_page_bare'],
            $numbered => ['title_page', 'title_page_short', 'title_page_bare'],
            LandingType::Building === $landing->type => ['title_count', 'title'],
            default => ['title_count', 'title', 'title_short', 'title_name'],
        };

        return $this->titles->fit(
            array_map(static fn (string $key): array => [$prefix.$key, $parameters], $keys),
            $this->heading($landing),
        );
    }

    /**
     * @return array<string, string>
     */
    private function parameters(LandingPage $landing): array
    {
        return match ($landing->type) {
            LandingType::Canton => ['canton' => (string) $landing->canton?->trans($this->translator)],
            LandingType::Town => ['town' => (string) $landing->townName],
            LandingType::Building => ['heading' => $this->heading($landing)],
        };
    }

    /** The page's h1, also TitleChain's last resort. */
    private function heading(LandingPage $landing): string
    {
        return LandingType::Building === $landing->type
            ? $this->translator->trans('landing.building.'.$landing->building.'.heading', [], 'search')
            : $this->translator->trans('landing.'.$landing->type->value.'.heading', $this->parameters($landing), 'search');
    }
}
