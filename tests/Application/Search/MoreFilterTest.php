<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Search\ReadModel\ListingQuery;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The "weitere Filter" slot (#12, FI-4): overlay, per-value hiding, pill and live-count contract.
 * Every test builds its own venues, because not every DemoStory code has a venue. The client
 * shares the test's connection (no reboot), so they are visible and rolled back afterwards.
 */
final class MoreFilterTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    /** FI-4's 11 building chips, in order. */
    private const PRIMARY_BUILDINGS = [
        'bar_club', 'event_venue', 'wedding_venue', 'hotel', 'party_room', 'restaurant',
        'castle', 'congress_centre', 'forest_hut', 'farm_barn', 'mountain_hut',
    ];

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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filterGroups(): iterable
    {
        yield 'q' => ['q=Seeblick', '#filter-more-q[value="Seeblick"]'];
        yield 'setting' => ['setting[]=lake', '#filter-more-setting-lake[checked]'];
        yield 'access' => ['access[]=station', '#filter-more-access-station[checked]'];
        yield 'building' => ['building[]=castle', '#filter-more-building-castle[checked]'];
        yield 'infra' => ['infra[]=wifi', '#filter-more-infra-wifi[checked]'];
        yield 'furniture' => ['furniture[]=flipchart', '#filter-more-furniture-flipchart[checked]'];
        yield 'tech' => ['tech[]=sound_system', '#filter-more-tech-sound_system[checked]'];
        yield 'surroundings' => ['surroundings[]=garden', '#filter-more-surroundings-garden[checked]'];
        yield 'rooms' => ['rooms=2', '#filter-more-rooms option[value="2"][selected]'];
        yield 'area' => ['area=100', '#filter-more-area option[value="100"][selected]'];
        yield 'height' => ['height=3.5', '#filter-more-height option[value="3.5"][selected]'];
    }

    #[DataProvider('filterGroups')]
    public function test_each_group_filters_and_renders_its_state(string $query, string $control): void
    {
        $this->everyFilterVenue('Schloss Seeblick');
        $this->venue(['name' => 'Remise Anderswo']);

        $crawler = $this->get('/hochzeitslocations?'.$query);

        self::assertSame(['Schloss Seeblick'], $this->names($crawler));
        self::assertCount(1, $crawler->filter($control));
        self::assertSame(['weitere Filter (1)'], $this->chips($crawler));
    }

    public function test_values_hide_one_by_one(): void
    {
        $this->terms($this->venue(), [[TermGroup::Setting, 'lake'], [TermGroup::Infrastructure, 'wifi']]);

        $crawler = $this->get('/hochzeitslocations');

        self::assertCount(1, $crawler->filter('#filter-more-setting-lake'));
        self::assertCount(0, $crawler->filter('#filter-more-setting-river'));
        self::assertCount(1, $crawler->filter('#filter-more-infra-wifi'));
        self::assertCount(0, $crawler->filter('#filter-more-infra-stage'));
    }

    public function test_access_needs_a_venue_within_the_threshold(): void
    {
        $this->venue([], static fn (Venue $venue) => $venue->changeDistances('1.5', 'Bahnhof', null, null, null, null));
        self::assertCount(0, $this->get('/hochzeitslocations')->filter('#filter-more-access-station'));

        $this->venue([], static fn (Venue $venue) => $venue->changeDistances('0.9', 'Bahnhof', null, null, null, null));
        self::assertCount(1, $this->get('/hochzeitslocations')->filter('#filter-more-access-station'));
    }

    public function test_a_venue_without_rooms_shows_no_room_filter(): void
    {
        $this->venue([], static fn (Venue $venue) => $venue->summariseRooms(0, null, null));

        self::assertCount(0, $this->get('/hochzeitslocations')->filter('#filter-more-rooms'));
    }

    public function test_room_controls_show_one_by_one(): void
    {
        $this->venue([], static fn (Venue $venue) => $venue->summariseRooms(2, null, null));

        $crawler = $this->get('/hochzeitslocations');

        self::assertCount(1, $crawler->filter('#filter-more-rooms'));
        self::assertCount(0, $crawler->filter('#filter-more-area'));
        self::assertCount(0, $crawler->filter('#filter-more-height'));
        self::assertSame(
            ['Anzahl Räume', '1 Raum', '2 Räume', '3 Räume', '4 Räume', '5 Räume', '≥ 6 Räume'],
            $crawler->filter('#filter-more-rooms option')->each(static fn (Crawler $option): string => $option->text()),
        );
    }

    public function test_the_room_options_carry_the_codec_values(): void
    {
        $this->venue([], static fn (Venue $venue) => $venue->summariseRooms(2, 120, '3.5'));

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame(
            ['', '50', '100', '150', '200', '250', '300'],
            $crawler->filter('#filter-more-area option')->each(static fn (Crawler $option): ?string => $option->attr('value')),
        );
        self::assertSame('≥ 300 m²', $crawler->filter('#filter-more-area option')->last()->text());
        self::assertSame(
            ['', '2.5', '3.0', '3.5', '4.0', '4.5', '5.0'],
            $crawler->filter('#filter-more-height option')->each(static fn (Crawler $option): ?string => $option->attr('value')),
        );
        self::assertSame('≥ 5.0 m', $crawler->filter('#filter-more-height option')->last()->text());
    }

    public function test_a_section_without_data_disappears_with_its_legend(): void
    {
        $this->terms($this->venue(), [[TermGroup::Infrastructure, 'wifi']]);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame(['Infrastruktur'], $crawler->filter('#filter-more legend')->each(static fn (Crawler $legend): string => $legend->text()));
        self::assertCount(2, $crawler->filter('.filter-more__group'), 'A rule never stands alone: Name and one group.');
    }

    public function test_an_active_value_without_data_stays_and_is_no_hidden_input(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations?infra[]=shower');

        self::assertCount(1, $crawler->filter('#filter-more-infra-shower[checked]'));
        self::assertSame(['weitere Filter (1)'], $this->chips($crawler));
        self::assertCount(0, $crawler->filter('#filter-more input[type="hidden"]'));
    }

    public function test_an_active_room_filter_without_data_stays(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations?rooms=2&access[]=airport');

        self::assertCount(1, $crawler->filter('#filter-more-rooms option[value="2"][selected]'));
        self::assertCount(0, $crawler->filter('#filter-more-area'));
        self::assertCount(1, $crawler->filter('#filter-more-access-airport[checked]'));
    }

    public function test_with_no_filter_data_only_the_name_field_shows(): void
    {
        $this->venue();

        $this->assertOnlyTheNameField($this->get('/hochzeitslocations'));
    }

    public function test_with_no_venues_at_all_only_the_name_field_shows(): void
    {
        $this->assertOnlyTheNameField($this->get('/hochzeitslocations'));
    }

    public function test_a_value_comes_back_when_a_venue_has_it(): void
    {
        $venue = $this->venue();
        self::assertCount(0, $this->get('/hochzeitslocations')->filter('#filter-more-setting-lake'));

        $this->terms($venue, [[TermGroup::Setting, 'lake']]);

        self::assertCount(1, $this->get('/hochzeitslocations')->filter('#filter-more-setting-lake'));
    }

    public function test_eleven_buildings_stay_flat(): void
    {
        $this->buildings(self::PRIMARY_BUILDINGS);

        $crawler = $this->get('/hochzeitslocations');

        self::assertCount(0, $crawler->filter('#filter-more details'));
        self::assertCount(11, $crawler->filter('#filter-more input[name="building[]"]'));
    }

    public function test_a_twelfth_building_goes_into_the_expander(): void
    {
        $this->buildings([...self::PRIMARY_BUILDINGS, 'cafe']);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame(self::PRIMARY_BUILDINGS, $crawler->filter('#filter-more fieldset > .filter-more__chips input[name="building[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame(['cafe'], $crawler->filter('#filter-more details input[name="building[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertCount(0, $crawler->filter('#filter-more details[open]'));
    }

    public function test_an_active_building_opens_the_expander(): void
    {
        $this->buildings([...self::PRIMARY_BUILDINGS, 'cafe']);

        $crawler = $this->get('/hochzeitslocations?building[]=hotel');

        self::assertCount(1, $crawler->filter('#filter-more details[open]'));
    }

    public function test_the_pill_counts_params_and_replaces_the_trigger(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations?setting[]=lake&setting[]=river&rooms=2');

        self::assertSame(['weitere Filter (2)'], $this->chips($crawler));
        self::assertSame('weitere Filter (2)', $crawler->filter('.filter-bar .chip button[popovertarget="filter-more"]')->text());
        self::assertCount(1, $crawler->filter('.filter-bar [popovertarget="filter-more"]:not([popovertargetaction])'));
        self::assertSame('/hochzeitslocations', $crawler->filter('.chip--more a')->attr('href'));
        self::assertSame('Weitere Filter entfernen', $crawler->filter('.chip--more a')->attr('aria-label'));
    }

    public function test_the_pill_leaves_the_other_slots_alone(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=80&setting[]=lake');

        self::assertSame(['Zürich', '80 Gäste', 'weitere Filter (1)'], $this->chips($crawler));
        self::assertSame('/hochzeitslocations?canton=zh&guests=80', $crawler->filter('.chip--more a')->attr('href'));
    }

    public function test_the_form_carries_the_other_slots_and_never_the_page(): void
    {
        for ($i = 0; $i <= ListingQuery::PER_PAGE; ++$i) {
            $this->venue(['capacityMax' => 200]);
        }

        $crawler = $this->get('/hochzeitslocations?guests=80&setting[]=lake&page=1');
        self::assertSame(['guests' => '80'], $this->hiddenState($crawler));

        $crawler = $this->get('/hochzeitslocations?guests=80&page=2');
        self::assertSame(['guests' => '80'], $this->hiddenState($crawler));
    }

    public function test_the_live_count_and_overlay_form_contract(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations');

        $form = $crawler->filter('form#filter-more-form[data-live-count][data-overlay-form]');
        self::assertCount(1, $form);
        self::assertSame('/_search/count', $form->attr('data-live-count-url'));
        self::assertSame('/hochzeitslocations', $form->attr('action'));
        self::assertSame('off', $form->attr('autocomplete'));
        $button = $crawler->filter('#filter-more .overlay__footer button[form="filter-more-form"][data-live-count-label]');
        self::assertSame('Keine Resultate', $button->attr('data-count-zero'));
        self::assertSame('1 Resultat anzeigen', $button->attr('data-count-one'));
        self::assertSame('%n Resultate anzeigen', $button->attr('data-count-other'));
        self::assertSame('Filter löschen', $crawler->filter('#filter-more a[data-overlay-clear]')->text());
        self::assertCount(1, $crawler->filter('script[src*="overlay-form"]'));
    }

    public function test_with_every_section_on_the_bar_stays_clean_and_the_page_public(): void
    {
        $this->everyFilterVenue('Schloss Seeblick');

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame(
            ['Lage', 'Erreichbarkeit', 'Gebäudeart', 'Infrastruktur', 'Mobiliar', 'Technik', 'Umgebung', 'Raumangebot'],
            $crawler->filter('#filter-more legend')->each(static fn (Crawler $legend): string => $legend->text()),
        );
        self::assertSame('Wähle weitere Filter & Möglichkeiten', $crawler->filter('#filter-more-title')->text());
        self::assertStringNotContainsString('Karte', $crawler->filter('.filter-bar')->text());
        self::assertCount(0, $crawler->filter('[style]'));
        $response = $this->client->getResponse();
        self::assertTrue($response->headers->getCacheControlDirective('public'));
        self::assertSame([], $response->headers->getCookies());
    }

    public function test_the_listing_page_runs_seven_queries(): void
    {
        $this->venue();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $holder->reset();
        $this->get('/hochzeitslocations');

        // Cards, photos, count, known terms, top cantons, filter flags, filter terms.
        self::assertCount(7, $holder->getData()['default'] ?? []);
    }

    private function assertOnlyTheNameField(Crawler $crawler): void
    {
        self::assertCount(0, $crawler->filter('#filter-more fieldset'));
        self::assertCount(1, $crawler->filter('.filter-more__group'), 'No rule renders.');
        self::assertCount(1, $crawler->filter('#filter-more-q[placeholder="Name eingeben"]'));
        self::assertSame('Suche eine konkrete Location', $crawler->filter('label[for="filter-more-q"]:not(.visually-hidden)')->text());
        self::assertSame('weitere Filter +', $crawler->filter('.filter-bar [popovertarget="filter-more"]:not([popovertargetaction])')->text());
        self::assertCount(1, $crawler->filter('#filter-more .overlay__footer button[form="filter-more-form"]'));
        self::assertCount(1, $crawler->filter('#filter-more a[data-overlay-clear]'));
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

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('.card__link')->each(static fn (Crawler $link): string => $link->text());
    }

    /**
     * @return array<string, string>
     */
    private function hiddenState(Crawler $crawler): array
    {
        $state = [];
        foreach ($crawler->filter('#filter-more input[type="hidden"]') as $input) {
            \assert($input instanceof \DOMElement);
            $state[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $state;
    }

    /**
     * A published venue with a German translation.
     *
     * @param array<string, mixed>         $attributes
     * @param (callable(Venue): void)|null $change     applied before the venue is stored
     */
    private function venue(array $attributes = [], ?callable $change = null): Venue
    {
        $factory = VenueFactory::new();
        $venue = (null === $change ? $factory : $factory->afterInstantiate($change(...)))->create($attributes);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    /**
     * A venue that matches one value of every group, so every section renders.
     */
    private function everyFilterVenue(string $name): void
    {
        $venue = $this->venue(['name' => $name], static function (Venue $venue): void {
            $venue->changeDistances('0.5', 'Bahnhof', '5.0', null, '20.0', null);
            $venue->summariseRooms(3, 150, '4.0');
        });
        $this->terms($venue, [
            [TermGroup::Setting, 'lake'],
            [TermGroup::Building, 'castle'],
            [TermGroup::Infrastructure, 'wifi'],
            [TermGroup::Furniture, 'flipchart'],
            [TermGroup::Technology, 'sound_system'],
            [TermGroup::Surroundings, 'garden'],
        ]);
    }

    /**
     * @param list<string> $codes
     */
    private function buildings(array $codes): void
    {
        $venue = $this->venue();
        $this->terms($venue, array_map(static fn (string $code): array => [TermGroup::Building, $code], $codes));
    }

    /**
     * @param list<array{TermGroup, string}> $terms
     */
    private function terms(Venue $venue, array $terms): void
    {
        foreach ($terms as [$group, $code]) {
            VenueTermFactory::new()->term($group, $code)->create(['venue' => $venue]);
        }
    }
}
