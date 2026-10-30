<?php

declare(strict_types=1);

namespace App\Seo;

use App\Search\ReadModel\ListingPage;
use App\Search\ReadModel\SearchCriteria;
use App\Seo\ValueObject\PageMeta;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SEO-1 and SEO-4 for the listing, driven by the parsed criteria: anything that survives
 * SearchCriteria's grammar other than `page` is a filter, so unknown parameters and empty values
 * count as the clean listing. A filter state is `noindex, follow` and canonicalises to the clean
 * path, whatever its page; an unfiltered page N canonicalises to itself.
 */
final class ListingMeta
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%site.name%')]
        private readonly string $site,
    ) {
    }

    public function for(SearchCriteria $criteria, ListingPage $page): PageMeta
    {
        $filtered = [] !== $criteria->withPage(1)->toQuery();
        $numbered = !$filtered && $page->page > 1;

        return new PageMeta(
            $this->translator->trans($numbered ? 'listing.title_page' : 'listing.title', ['site' => $this->site, 'page' => (string) $page->page], 'seo'),
            $this->translator->trans($numbered ? 'listing.description_page' : 'listing.description', ['page' => (string) $page->page], 'seo'),
            $this->urls->generate('listing', $numbered ? ['page' => $page->page] : [], UrlGeneratorInterface::ABSOLUTE_URL),
            $filtered,
            'website',
        );
    }
}
