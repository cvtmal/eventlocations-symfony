<?php

declare(strict_types=1);

namespace App\Search\Controller;

use App\Search\LandingPaths;
use App\Search\ReadModel\HomeHero;
use App\Search\ReadModel\HomeHeroQuery;
use App\Search\ReadModel\LandingPages;
use App\Search\ReadModel\ListingQuery;
use App\Search\ValueObject\HomeCategory;
use App\Shared\Http\PublicCache;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The home page `/` (#36): hero with a plain GET search, category tiles, Spotlight and the live
 * regions. No session, publicly cacheable (S10). Its lifetime is `PublicCache::listing()`, not
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

        $response = $this->render('search/home.html.twig', [
            'cards' => $cards,
            'hero' => $this->hero->photo($this->heroPhoto, $locale) ?? HomeHero::fromCards($cards),
            'categories' => $this->categories(),
            'regions' => $this->regions($locale),
        ]);

        return $response->setCache(PublicCache::listing($now));
    }

    /**
     * @return list<array{category: HomeCategory, href: string}>
     */
    private function categories(): array
    {
        return array_map(
            fn (HomeCategory $category): array => [
                'category' => $category,
                'href' => $this->paths->url(['building' => [$category->value]]),
            ],
            HomeCategory::cases(),
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
