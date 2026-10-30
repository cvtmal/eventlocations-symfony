<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueRatingHighlightFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * The public listing /hochzeitslocations (#7). The client shares the test's connection (no
 * reboot), so the venues created here are visible to the request and rolled back afterwards.
 */
final class ListingControllerTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_renders_hero_cards_and_facts(): void
    {
        $rated = VenueFactory::new()->rated('9.0', 474)->create([
            'name' => 'Schloss Seeburg', 'tier' => Tier::Premium, 'capacityMin' => 50, 'capacityMax' => 450, 'town' => $this->town('Stäfa'),
        ]);
        VenueTranslationFactory::createOne(['venue' => $rated, 'slug' => 'schloss-seeburg']);
        foreach ([RatingHighlight::Food, RatingHighlight::Atmosphere, RatingHighlight::Value] as $position => $code) {
            VenueRatingHighlightFactory::createOne(['venue' => $rated, 'position' => $position + 1, 'code' => $code]);
        }
        $this->venue(['name' => 'Gasthof Linde'], ['slug' => 'gasthof-linde']);
        $this->venue(['name' => 'Remise Au']);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSelectorTextSame('h1', 'Hochzeitslocations in der Schweiz');
        self::assertSelectorTextSame('.listing-hero__count', '3 Resultate');
        self::assertCount(3, $crawler->filter('.card'));

        $card = $this->card($crawler, 'Schloss Seeburg');
        self::assertSame('/hochzeitslocation/schloss-seeburg', $card->filter('.card__link')->attr('href'));
        self::assertSame(['50 - 450', 'Stäfa'], $card->filter('.card-facts__item')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame('Premium', $card->filter('.card__title .badge')->text());
        self::assertStringStartsWith('Herausragend (9.0) | 474', $card->filter('.rating-summary__line')->text());
        self::assertCount(1, $card->filter('.rating-summary__line svg[role="img"][aria-label="Bewertungen"]'));
        self::assertSame(
            ['“Feines Essen”', '“Stilvolle Atmosphäre”', '“Top Preis/Leistung”'],
            $card->filter('.rating-summary__highlights li')->each(static fn (Crawler $item): string => $item->text()),
        );

        $basic = $this->card($crawler, 'Gasthof Linde');
        self::assertSame('/hochzeitslocation/gasthof-linde', $basic->filter('.card__link')->attr('href'));
        self::assertCount(0, $basic->filter('.badge'));
        self::assertCount(0, $basic->filter('.card__rating'));

        $this->get('/hochzeitslocations?q=Linde');
        self::assertSelectorTextSame('.listing-hero__count', '1 Resultat');
    }

    public function test_carousel_and_image_loading(): void
    {
        foreach (['Schloss Seeburg', 'Gasthof Linde', 'Remise Au'] as $name) {
            $venue = $this->venue(['name' => $name, 'tier' => Tier::Premium]);
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        }
        $this->venue(['name' => 'Ohne Bilder']); // basic: always last

        $crawler = $this->get('/hochzeitslocations');

        self::assertCount(3, $crawler->filter('[data-carousel]'));
        self::assertCount(3, $crawler->filter('[data-carousel] [data-carousel-counter]'));
        self::assertCount(3, $crawler->filter('[data-carousel] [data-carousel-prev]'));

        $priority = $crawler->filter('img[fetchpriority="high"]');
        self::assertCount(1, $priority);
        $items = $crawler->filter('.listing__item');
        self::assertSame($priority->attr('src'), $items->eq(0)->filter('img')->first()->attr('src'));
        $second = $items->eq(1)->filter('img')->first();
        self::assertSame('eager', $second->attr('loading'));
        self::assertNull($second->attr('fetchpriority'));
        self::assertCount(6, $crawler->filter('.listing__results img'));
        self::assertCount(4, $crawler->filter('.listing__results img[loading="lazy"]'));

        $widths = [];
        foreach ($crawler->filter('.listing__results img, .listing__results source') as $element) {
            \assert($element instanceof \DOMElement);
            preg_match_all('/ (\d+)w/', $element->getAttribute('srcset'), $matches);
            $widths = [...$widths, ...$matches[1]];
        }
        self::assertSame(['360', '640', '960'], array_values(array_unique($widths)));

        // LP-2 + LP-3: every photo links to the detail page, without an extra tab stop or screen-reader link.
        foreach (['Schloss Seeburg', 'Gasthof Linde', 'Remise Au'] as $name) {
            $card = $this->card($crawler, $name);
            $href = (string) $card->filter('.card__link')->attr('href');
            self::assertStringStartsWith('/hochzeitslocation/', $href);
            self::assertCount(2, $card->filter('.carousel__slide'));
            self::assertCount(2, $card->filter(\sprintf(
                '.carousel__slide > a.card__photo-link[href="%s"][tabindex="-1"][aria-hidden="true"] img',
                $href,
            )), $name);
        }

        $placeholder = $this->card($crawler, 'Ohne Bilder');
        self::assertCount(1, $placeholder->filter('.card__placeholder'));
        self::assertCount(0, $placeholder->filter('[data-carousel]'));
    }

    public function test_merken_form_posts_to_the_literal_path(): void
    {
        $venue = $this->venue(['name' => 'Schloss Seeburg']);
        $number = $venue->number();

        $crawler = $this->get('/hochzeitslocations');

        $button = $crawler->filter(\sprintf(
            'form.merken.merken--card[method="post"][action="/merkliste/%d"] button[type="submit"][aria-pressed="false"][data-merken="%d"]',
            $number,
            $number,
        ));
        self::assertCount(1, $button);
        self::assertSame('Schloss Seeburg merken', $button->attr('aria-label'));
        self::assertSame('Merken', $button->filter('.merken__text')->text());
    }

    public function test_pagination_over_49_venues(): void
    {
        for ($i = 0; $i < 49; ++$i) {
            $this->venue([]);
        }

        $crawler = $this->get('/hochzeitslocations');
        self::assertCount(48, $crawler->filter('.card'));
        self::assertSelectorTextSame('.pagination__count', '1 - 48 von 49 Resultaten');
        self::assertSame('1', $crawler->filter('.pagination [aria-current="page"]')->text());
        self::assertCount(1, $crawler->filter('.pagination__page[href="/hochzeitslocations?page=2"]'));
        self::assertCount(1, $crawler->filter('.pagination__arrow[href="/hochzeitslocations?page=2"][aria-label="Nächste Seite"]'));
        self::assertSame('http://localhost/hochzeitslocations', $crawler->filter('link[rel="canonical"]')->attr('href'));

        $crawler = $this->get('/hochzeitslocations?page=2');
        self::assertCount(1, $crawler->filter('.card'));
        self::assertSelectorTextSame('.pagination__count', '49 - 49 von 49 Resultaten');
        self::assertSame('2', $crawler->filter('.pagination [aria-current="page"]')->text());
        self::assertCount(1, $crawler->filter('.pagination__arrow[href="/hochzeitslocations"][aria-label="Vorherige Seite"]'));
        self::assertSame('http://localhost/hochzeitslocations?page=2', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/hochzeitslocations?page=2', $crawler->filter('link[hreflang="de-CH"]')->attr('href'));
    }

    public function test_query_string_filters_narrow_results_and_render_chips(): void
    {
        $lake = $this->venue(['name' => 'Seehaus Zürich', 'town' => $this->town('Zürich')]);
        VenueTermFactory::new()->term(TermGroup::Setting, 'lake')->create(['venue' => $lake]);
        $river = $this->venue(['name' => 'Remise Zürich', 'town' => $this->town('Zürich')]);
        VenueTermFactory::new()->term(TermGroup::Setting, 'river')->create(['venue' => $river]);
        $this->venue(['name' => 'Landgut Bern', 'town' => $this->town('Bern')]);

        $crawler = $this->get('/hochzeitslocations?canton=zh');
        self::assertSame(['Remise Zürich', 'Seehaus Zürich'], $this->names($crawler));
        $chip = $crawler->filter('.filter-bar .chip');
        self::assertCount(1, $chip);
        self::assertSame('Zürich', $chip->text());
        self::assertSame('/hochzeitslocations', $chip->attr('href'));
        self::assertSame('Filter Zürich entfernen', $chip->attr('aria-label'));

        $crawler = $this->get('/hochzeitslocations?guests=120');
        self::assertSame(['120 Gäste'], $crawler->filter('.filter-bar .chip')->each(static fn (Crawler $c): string => $c->text()));

        $crawler = $this->get('/hochzeitslocations?setting[]=lake&setting[]=river');
        self::assertSame(['Remise Zürich', 'Seehaus Zürich'], $this->names($crawler));
        $chips = $crawler->filter('.filter-bar .chip');
        self::assertSame(['Am See', 'Am Fluss'], $chips->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['/hochzeitslocations?setting%5B0%5D=river', '/hochzeitslocations?setting%5B0%5D=lake'], $chips->each(static fn (Crawler $c): ?string => $c->attr('href')));

        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=120&setting[]=lake&page=1');
        self::assertSame('/hochzeitslocations?guests=120&setting%5B0%5D=lake', $crawler->filter('#filter-region .overlay__footer a')->attr('href'));
        self::assertSame('/hochzeitslocations?canton=zh&setting%5B0%5D=lake', $crawler->filter('#filter-guests .overlay__footer a')->attr('href'));
        self::assertSame('/hochzeitslocations?canton=zh&guests=120', $crawler->filter('#filter-more .overlay__footer a')->attr('href'));
    }

    public function test_filter_bar_has_three_triggers_and_overlays(): void
    {
        $this->venue([]);

        $crawler = $this->get('/hochzeitslocations');

        foreach (['filter-region' => 'Region', 'filter-guests' => 'Anzahl Gäste', 'filter-more' => 'weitere Filter +'] as $id => $label) {
            self::assertSame($label, $crawler->filter(\sprintf('.filter-bar [popovertarget="%s"]:not([popovertargetaction])', $id))->text());
            self::assertCount(1, $crawler->filter(\sprintf('[data-overlay][popover]#%s', $id)));
            self::assertSame('Filter löschen', $crawler->filter(\sprintf('#%s .overlay__footer a', $id))->text());
        }
        self::assertCount(0, $crawler->filter('.filter-bar .chip'));
        self::assertStringNotContainsString('Karte', $crawler->filter('.filter-bar')->text());
        self::assertSame('/', $crawler->filter('.filter-bar__aside a.btn--round')->attr('href'));
        self::assertSame('Merkliste', $crawler->filter('.filter-bar__aside a.btn--round')->text());
    }

    public function test_empty_state(): void
    {
        $this->venue([]);

        $crawler = $this->get('/hochzeitslocations?q=zzzzzz');

        self::assertSelectorTextSame('.listing-hero__count', '0 Resultate');
        self::assertSelectorTextSame('.listing-empty__title', 'Keine Location passt zu deinen Filtern');
        self::assertSame('/hochzeitslocations', $crawler->filter('.listing-empty a')->attr('href'));
        self::assertSame('Filter löschen', $crawler->filter('.listing-empty a')->text());
        self::assertCount(0, $crawler->filter('.card'));
        self::assertCount(0, $crawler->filter('.pagination'));
    }

    public function test_page_beyond_last_is_404(): void
    {
        $this->client->request('GET', '/hochzeitslocations?page=2');
        self::assertResponseStatusCodeSame(404);
        $this->get('/hochzeitslocations');

        for ($i = 0; $i < 3; ++$i) {
            $this->venue([]);
        }
        $this->client->request('GET', '/hochzeitslocations?page=2');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->get('/hochzeitslocations?page=abc');
        self::assertCount(3, $crawler->filter('.card'));
    }

    public function test_public_cache_headers_and_no_cookie(): void
    {
        $this->venue([]);

        $this->get('/hochzeitslocations?canton=zh');
        $response = $this->client->getResponse();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=600', $cacheControl);
        self::assertStringContainsString('stale-while-revalidate=86400', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame([], $this->client->getCookieJar()->all());
        // The controller sets none; the framework adds `noindex` whenever kernel.debug is on
        // (framework.disallow_search_engine_index), so prod sends none.
        self::assertSame(self::getContainer()->getParameter('kernel.debug') ? 'noindex' : null, $response->headers->get('X-Robots-Tag'));
    }

    public function test_related_searches_link_top_cantons(): void
    {
        $this->venue(['town' => $this->town('Zürich')]);
        $this->venue(['town' => $this->town('Stäfa')]);
        $this->venue(['town' => $this->town('Bern')]);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSelectorTextSame('.related__title', 'Andere suchten auch nach');
        $links = $crawler->filter('.related__list a');
        self::assertSame(['Hochzeit Zürich', 'Hochzeit Bern'], $links->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('/hochzeitslocations?canton=zh', $links->first()->attr('href'));
    }

    public function test_meta_title_description_and_single_h1(): void
    {
        $this->venue([]);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame('Hochzeitslocations in der Schweiz | Hochzeitslocation', $crawler->filter('title')->text());
        $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
        self::assertNotSame('', $description);
        self::assertLessThanOrEqual(155, mb_strlen($description));
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(0, $crawler->filter('[style]'));
        self::assertCount(0, $crawler->filter('script:not([src])'));
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function card(Crawler $crawler, string $name): Crawler
    {
        $card = $crawler->filter('.card')->reduce(static fn (Crawler $card): bool => $card->filter('.card__link')->text() === $name);
        self::assertCount(1, $card, $name);

        return $card;
    }

    /**
     * @return list<string> sorted
     */
    private function names(Crawler $crawler): array
    {
        $names = $crawler->filter('.card__link')->each(static fn (Crawler $link): string => $link->text());
        sort($names);

        return $names;
    }

    /**
     * A published venue with a German translation.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $translation
     */
    private function venue(array $attributes, array $translation = []): Venue
    {
        $venue = VenueFactory::createOne($attributes);
        VenueTranslationFactory::createOne(['venue' => $venue] + $translation);

        return $venue;
    }

    private function town(string $name): Town
    {
        $town = repository(Town::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Town::class, $town);

        return $town;
    }
}
