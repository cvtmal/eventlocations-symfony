<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Search\LandingPaths;
use App\Search\ReadModel\FilterDataQuery;
use App\Search\ReadModel\HomeHero;
use App\Search\ReadModel\HomeHeroQuery;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\ListingQuery;
use App\Search\ValueObject\HomeCategory;
use App\Shared\Http\PublicCache;
use App\Venues\ValueObject\TermGroup;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The home page `/` (#36): hero with a plain GET search (Kanton, Anlass, Anzahl Gäste; F5), the
 * event-type tiles, Spotlight and the live regions. No session, publicly cacheable (S10). Its lifetime is `PublicCache::listing()`, not
 * `page()`: Spotlight is the head of the listing in LP-4's order, so it ends with the day's order
 * seed at the next Zurich midnight. There is no `#[Cache]`, because nothing here throws.
 */
final class HomeController extends AbstractController
{
    private const int SPOTLIGHT = 3;
    private const int SPOTLIGHT_PHOTOS = 1;

    public function __construct(
        private readonly ListingQuery $listing,
        private readonly HomeHeroQuery $hero,
        private readonly LandingPages $landings,
        private readonly LandingPaths $paths,
        private readonly FilterDataQuery $filterData,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        #[Autowire('%home.hero_photo%')]
        private readonly string $heroPhoto,
    ) {
    }

    #[Route(path: ['de' => '/'], name: 'home', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // Before the query, which takes its day seed then: a render crossing midnight must not
        // store yesterday's order with a full day's lifetime.
        $now = $this->clock->now();
        $locale = $request->getLocale();
        $cards = $this->listing->first(self::SPOTLIGHT, self::SPOTLIGHT_PHOTOS, $locale);

        // The event types a published venue carries: the Anlass select and the tiles (the filter's hiding rule).
        $events = $this->filterData->terms(TermGroup::SuitableFor);

        $response = $this->render('search/home.html.twig', [
            'cards' => $cards,
            'hero' => $this->hero->photo($this->heroPhoto, $locale) ?? HomeHero::fromCards($cards),
            'categories' => $this->categories($events),
            'regions' => $this->regions($locale),
            'events' => $events,
        ]);

        return $response->setCache(PublicCache::listing($now));
    }

    /**
     * The tiles in HomeCategory order, without the event types no published venue carries.
     *
     * @param list<string> $events
     *
     * @return list<array{category: HomeCategory, href: string}>
     */
    private function categories(array $events): array
    {
        return array_map(
            fn (HomeCategory $category): array => [
                'category' => $category,
                'href' => $this->paths->url(['event' => [$category->value]]),
            ],
            array_values(array_filter(HomeCategory::cases(), static fn (HomeCategory $category): bool => \in_array($category->value, $events, true))),
        );
    }

    /**
     * The live canton pages, by name, for the region chips and the form's canton select.
     *
     * @return list<array{code: string, label: string, href: string}>
     */
    private function regions(string $locale): array
    {
        $regions = [];
        foreach ($this->landings->cantons() as $page) {
            if (null !== $page->canton) {
                $regions[] = [
                    'code' => $page->canton->value,
                    'label' => $page->canton->trans($this->translator),
                    'href' => $this->paths->path($page),
                ];
            }
        }

        $collator = new \Collator($locale);
        usort($regions, static fn (array $a, array $b): int => (int) $collator->compare($a['label'], $b['label']));

        return $regions;
    }
}
