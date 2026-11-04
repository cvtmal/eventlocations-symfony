<?php

declare(strict_types=1);

namespace App\Seo\Extension;

use App\Search\LandingPaths;
use App\Search\ReadModel\LandingPage;
use App\Search\ReadModel\LandingPages;
use App\Search\ValueObject\LandingType;
use App\Seo\ListingMeta;
use App\Shared\Extension\LandingPageSource;
use App\Shared\ValueObject\LandingPageEntry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The landing pages the back office writes texts for (#26, P3): every candidate of #17's
 * LandingPages (one counting SQL, threshold 1), with what the public page shows without a
 * staff text. Seo implements it because the default title and description are ListingMeta's,
 * and Search may not depend on Seo. Ordered by type (canton, town, building), then by name.
 * Memoized per request; tagged kernel.reset by autoconfiguration.
 */
final class LandingPageCatalogue implements LandingPageSource, ResetInterface
{
    private const array TYPE_ORDER = [LandingType::Canton->value => 0, LandingType::Town->value => 1, LandingType::Building->value => 2];

    /** @var array<string, LandingPageEntry>|null by key prefix */
    private ?array $entries = null;

    public function __construct(
        private readonly LandingPages $pages,
        private readonly LandingPaths $paths,
        private readonly ListingMeta $meta,
        private readonly TranslatorInterface $translator,
        #[Autowire('%landing.min_venues%')]
        private readonly int $minVenues,
    ) {
    }

    public function pages(): array
    {
        return array_values($this->entries());
    }

    public function page(string $keyPrefix): ?LandingPageEntry
    {
        return $this->entries()[$keyPrefix] ?? null;
    }

    public function reset(): void
    {
        $this->entries = null;
    }

    /**
     * @return array<string, LandingPageEntry>
     */
    private function entries(): array
    {
        if (null !== $this->entries) {
            return $this->entries;
        }

        $entries = array_map($this->entry(...), $this->pages->candidates());
        $collator = new \Collator($this->translator->getLocale());
        usort($entries, static fn (LandingPageEntry $a, LandingPageEntry $b): int => (self::TYPE_ORDER[$a->type] <=> self::TYPE_ORDER[$b->type])
            ?: (int) $collator->compare($a->name, $b->name));

        $this->entries = [];
        foreach ($entries as $entry) {
            $this->entries[$entry->keyPrefix] = $entry;
        }

        return $this->entries;
    }

    private function entry(LandingPage $page): LandingPageEntry
    {
        $passes = $page->venues >= $this->minVenues;
        $defaults = $this->meta->defaults($page);

        return new LandingPageEntry(
            $page->keyPrefix(),
            $page->type->value,
            $this->heading($page),
            $page->venues,
            $passes,
            $passes ? $this->paths->path($page, [], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            $this->intro($page),
            $defaults['title'],
            $defaults['description'],
        );
    }

    /** The page's h1, as `search/listing.html.twig` builds it. */
    private function heading(LandingPage $page): string
    {
        return LandingType::Building === $page->type
            ? $this->translator->trans('landing.building.'.$page->building.'.heading', [], 'search')
            : $this->translator->trans('landing.'.$page->type->value.'.heading', $this->place($page), 'search');
    }

    /** #17's intro sentence with its count, as `search/listing.html.twig` builds it. */
    private function intro(LandingPage $page): string
    {
        return LandingType::Building === $page->type
            ? $this->translator->trans('landing.building.'.$page->building.'.intro', ['count' => $page->venues], 'search')
            : $this->translator->trans('landing.'.$page->type->value.'.intro', $this->place($page) + ['count' => $page->venues], 'search');
    }

    /**
     * @return array{canton: string, canton_code: string, town: string}
     */
    private function place(LandingPage $page): array
    {
        return ['canton' => (string) $page->canton?->trans($this->translator), 'canton_code' => (string) $page->canton?->value, 'town' => (string) $page->townName];
    }
}
