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
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
        // The photo column sits above the title link's overlay, so the placeholder must link itself.
        self::assertSame($placeholder->filter('.card__link')->attr('href'), $placeholder->filter('a.card__placeholder')->attr('href'));
        self::assertSame('-1', $placeholder->filter('a.card__placeholder')->attr('tabindex'));
        self::assertCount(0, $placeholder->filter('[data-carousel]'));
    }

    public function test_merken_form_posts_to_the_toggle_route(): void
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
        self::assertCount(1, $crawler->filter(\sprintf('form[action="/merkliste/%d"] input[type="hidden"][name="_token"][value="csrf-token"]', $number)));
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
        self::assertSame(['weitere Filter (1)'], $crawler->filter('.filter-bar .chip')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('/hochzeitslocations', $crawler->filter('.filter-bar .chip a')->attr('href'));
        self::assertCount(1, $crawler->filter('#filter-more input[name="setting[]"][value="lake"][checked]'));

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
        self::assertSame('/merkliste', $crawler->filter('.filter-bar__aside a.btn--round')->attr('href'));
        self::assertSame('Merkliste', $crawler->filter('.filter-bar__aside a.btn--round')->text());
    }

    public function test_region_filters_narrow_results_through_the_query_string(): void
    {
        $zurich = $this->town('Zürich');
        $stafa = $this->town('Stäfa');
        $bern = $this->town('Bern');
        $this->venue(['name' => 'Remise Zürich', 'town' => $zurich, 'lat' => $zurich->lat(), 'lng' => $zurich->lng()]);
        $this->venue(['name' => 'Seehaus Stäfa', 'town' => $stafa, 'zip' => '8712', 'lat' => $stafa->lat(), 'lng' => $stafa->lng()]);
        $this->venue(['name' => 'Landgut Bern', 'town' => $bern, 'lat' => $bern->lat(), 'lng' => $bern->lng()]);

        $crawler = $this->get('/hochzeitslocations?canton=zh');
        self::assertSame(['Remise Zürich', 'Seehaus Stäfa'], $this->names($crawler));
        self::assertSame(['Zürich'], $this->chips($crawler));

        $crawler = $this->get('/hochzeitslocations?place='.$stafa->id().'&radius=0');
        self::assertSame(['Seehaus Stäfa'], $this->names($crawler));
        self::assertSame(['Stäfa'], $this->chips($crawler));

        $crawler = $this->get('/hochzeitslocations?place='.$stafa->id().'&radius=25');
        self::assertSame(['Remise Zürich', 'Seehaus Stäfa'], $this->names($crawler));
        self::assertSame(['Stäfa + 25 km'], $this->chips($crawler));
        self::assertSame('/hochzeitslocations', $crawler->filter('.filter-bar .chip')->attr('href'));

        $crawler = $this->get('/hochzeitslocations?place=8712');
        self::assertSame(['Seehaus Stäfa'], $this->names($crawler));
        self::assertSame(['8712 Stäfa + 5 km'], $this->chips($crawler));
        $place = $crawler->filter('#filter-region-form input[type="hidden"][name="place"]');
        self::assertSame('8712', $place->attr('value'));
        self::assertEqualsWithDelta($stafa->lat(), (float) $place->attr('data-lat'), 0.0001);
        self::assertEqualsWithDelta($stafa->lng(), (float) $place->attr('data-lng'), 0.0001);
        self::assertSame('8712 Stäfa', $crawler->filter('#filter-region-place')->attr('data-display'));

        self::assertSame(['9999 + 5 km'], $this->chips($this->get('/hochzeitslocations?place=9999')));
        self::assertSame(['Ort + 5 km'], $this->chips($this->get('/hochzeitslocations?place=0198a3c4-6b1e-7f3a-9c2d-1e4f5a6b7c8d')));
    }

    public function test_region_overlay_is_a_get_form_that_keeps_other_filters(): void
    {
        $this->venue(['town' => $this->town('Zürich')]);

        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=80&setting[]=lake');
        $form = $crawler->filter('#filter-region form#filter-region-form');

        self::assertCount(1, $form);
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/hochzeitslocations', $form->attr('action'));
        self::assertSame('/_search/count?', $form->attr('data-live-count-url'));
        self::assertSame('Wo soll eure Hochzeit stattfinden?', $crawler->filter('#filter-region-title')->text());

        // The other groups travel as hidden state; this overlay's own fields do not.
        self::assertSame('80', $form->filter('input[type="hidden"][name="guests"]')->attr('value'));
        self::assertSame('lake', $form->filter('input[type="hidden"][name="setting[]"]')->attr('value'));
        self::assertCount(0, $form->filter('input[type="hidden"][name="radius"]'));

        $button = $crawler->filter('#filter-region .overlay__footer button');
        self::assertSame('submit', $button->attr('type'));
        self::assertSame('filter-region-form', $button->attr('form'));
        self::assertSame('Resultate anzeigen', $button->text());
        self::assertSame('Keine Resultate', $button->attr('data-count-zero'));
        self::assertSame('1 Resultat anzeigen', $button->attr('data-count-one'));
        self::assertSame('%n Resultate anzeigen', $button->attr('data-count-other'));

        // No JS: the <select> and the text field work, and the hidden fields stay out of the way.
        $options = $form->filter('noscript select[name="canton"] option');
        self::assertCount(27, $options);
        self::assertSame('Alle Kantone', $options->first()->text());
        self::assertSame('zh', $form->filter('noscript select[name="canton"] option[selected]')->attr('value'));
        self::assertSame('zh', $form->filter('input[type="hidden"][name="canton"][disabled]')->attr('value'));
        self::assertCount(1, $form->filter('input[type="hidden"][name="place"][disabled]'));
        self::assertCount(1, $form->filter('[role="tablist"][hidden]'));

        self::assertCount(26, $form->filter('[role="option"]'));
        self::assertSame('Zürich', $form->filter('#filter-region-canton')->attr('value'));
        self::assertNull($form->filter('#filter-region-canton')->attr('name'));

        $radius = $form->filter('input[type="range"][name="radius"]');
        self::assertSame('0', $radius->attr('min'));
        self::assertSame('50', $radius->attr('max'));
        self::assertSame('5', $radius->attr('value'));

        self::assertCount(1, $form->filter('#ch-zh'));
        self::assertStringContainsString('Bundesamt für Statistik (BFS)', $form->filter('.region-filter__credit')->text());
    }

    public function test_venues_without_a_town_are_listed(): void
    {
        $this->venue(['name' => 'Gasthof Linde', 'town' => $this->town('Stäfa')]);
        DetailVenues::inCantonOnly();
        DetailVenues::inRegionOnly($this->em(), 'Schweizweit');
        DetailVenues::withoutPlace();

        $crawler = $this->get('/hochzeitslocations');

        self::assertSelectorTextSame('.listing-hero__count', '4 Resultate');
        self::assertSame(['Gasthof Linde', 'Remise Nur Region', 'Scheune Nur Kanton', 'Wiesenhof Ohne Ort'], $this->names($crawler));
    }

    public function test_a_card_shows_what_is_known_about_the_place(): void
    {
        DetailVenues::inCantonOnly();
        DetailVenues::inRegionOnly($this->em(), 'Schweizweit');
        DetailVenues::withoutPlace();

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame(['Kanton Zürich'], $this->cardPlace($crawler, 'Scheune Nur Kanton'));
        self::assertSame(['Schweizweit'], $this->cardPlace($crawler, 'Remise Nur Region'));
        self::assertSame([], $this->cardPlace($crawler, 'Wiesenhof Ohne Ort'), 'no pin and no empty item');
    }

    public function test_a_canton_filter_keeps_the_canton_only_venue_and_not_the_region_only_one(): void
    {
        DetailVenues::inCantonOnly();
        DetailVenues::inRegionOnly($this->em(), 'Schweizweit');

        $crawler = $this->get('/hochzeitslocations?canton=zh');

        self::assertSame(['Scheune Nur Kanton'], $this->names($crawler));
    }

    public function test_a_radius_search_excludes_venues_without_a_town(): void
    {
        $this->venue(['name' => 'Gasthof Linde', 'town' => $this->town('Zürich')]);
        DetailVenues::inCantonOnly();
        DetailVenues::withoutPlace();

        $crawler = $this->get('/hochzeitslocations?place='.$this->town('Zürich')->id().'&radius=10');

        self::assertSame(['Gasthof Linde'], $this->names($crawler));
    }

    public function test_the_region_form_offers_only_cantons_until_a_venue_has_a_town(): void
    {
        DetailVenues::inCantonOnly();
        DetailVenues::withoutPlace();

        $form = $this->get('/hochzeitslocations')->filter('#filter-region-form');

        self::assertStringContainsString('region-filter--canton-only', (string) $form->attr('class'));
        self::assertNotNull($form->filter('#filter-region-place')->attr('disabled'));
        self::assertNotNull($form->filter('#filter-region-radius')->attr('disabled'));

        // region-filter.js reads every one of these unguarded.
        foreach (['[role="tablist"][hidden]', '[data-panel="canton"]', '[data-panel="place"]', '#filter-region-place-list', 'output', '[data-region-map]', '[data-region-tooltip]', '[data-region-value="place"]', '[data-region-value="canton"]'] as $selector) {
            self::assertCount(1, $form->filter($selector), $selector);
        }
    }

    public function test_the_ort_tab_comes_back_with_the_first_town(): void
    {
        DetailVenues::inCantonOnly();
        $this->venue(['name' => 'Gasthof Linde', 'town' => $this->town('Stäfa')]);

        $form = $this->get('/hochzeitslocations')->filter('#filter-region-form');

        self::assertStringNotContainsString('region-filter--canton-only', (string) $form->attr('class'));
        self::assertNull($form->filter('#filter-region-place')->attr('disabled'));
        self::assertNull($form->filter('#filter-region-radius')->attr('disabled'));
    }

    public function test_a_bookmarked_place_keeps_the_full_form_and_its_chip(): void
    {
        DetailVenues::inCantonOnly();

        $crawler = $this->get('/hochzeitslocations?place=8001');

        $form = $crawler->filter('#filter-region-form');
        self::assertStringNotContainsString('region-filter--canton-only', (string) $form->attr('class'));
        self::assertNull($form->filter('#filter-region-place')->attr('disabled'));
        self::assertSame('8001', $form->filter('#filter-region-place')->attr('value'));
        self::assertCount(1, $crawler->filter('.filter-bar .chip'));
    }

    public function test_the_canton_only_form_keeps_the_page_public_and_cookie_free(): void
    {
        DetailVenues::inCantonOnly();

        $this->get('/hochzeitslocations');

        $response = $this->client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame([], $response->headers->getCookies());
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
        self::assertMatchesRegularExpression('/\bs-maxage=\d+\b/', $cacheControl);
        self::assertStringContainsString('stale-while-revalidate=86400', $cacheControl);
        self::assertStringContainsString('stale-if-error=86400', $cacheControl);
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame([], $this->client->getCookieJar()->all());
        // The controller sets none; the framework adds `noindex` whenever kernel.debug is on
        // (framework.disallow_search_engine_index), so prod sends none.
        self::assertSame(self::getContainer()->getParameter('kernel.debug') ? 'noindex' : null, $response->headers->get('X-Robots-Tag'));
    }

    public function test_related_searches_link_the_live_canton_pages(): void
    {
        foreach (['Zürich' => 3, 'Stäfa' => 3, 'Bern' => 5, 'Luzern' => 4] as $town => $count) {
            for ($i = 0; $i < $count; ++$i) {
                $this->venue(['town' => $this->town($town)]);
            }
        }

        $crawler = $this->get('/hochzeitslocations');

        self::assertSelectorTextSame('.related__title', 'Andere suchten auch nach');
        $links = $crawler->filter('.related__list a');
        self::assertSame(['Hochzeit Zürich', 'Hochzeit Bern'], $links->each(static fn (Crawler $a): string => $a->text()), 'Most venues first; Luzern is under the threshold of 5.');
        self::assertSame('/hochzeitslocations/zuerich', $links->first()->attr('href'));
    }

    public function test_no_related_searches_without_a_landing_page(): void
    {
        $this->venue(['town' => $this->town('Zürich')]);

        $this->get('/hochzeitslocations');

        self::assertSelectorNotExists('.related');
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
        self::assertCount(0, $crawler->filter('script:not([src]):not([type="application/ld+json"])'));
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function chips(Crawler $crawler): array
    {
        return $crawler->filter('.filter-bar .chip')->each(static fn (Crawler $chip): string => $chip->text());
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
     * The facts of a card without its capacity, which is always first.
     *
     * @return list<string>
     */
    private function cardPlace(Crawler $crawler, string $name): array
    {
        $card = $crawler->filter('.card')->reduce(static fn (Crawler $card): bool => $card->filter('.card__link')->text() === $name);

        return \array_slice($card->filter('.card-facts__item')->each(static fn (Crawler $item): string => $item->text()), 1);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
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
