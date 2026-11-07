<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueTranslation;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The venue's event facts on the listing (#33, FI-7, LP-7): the price and amenity group of the
 * "weitere Filter" overlay and the card tags. Set up like MoreFilterTest: own venues, the test's
 * connection, rolled back afterwards.
 */
final class EventFactsFilterTest extends WebTestCase
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filterParams(): iterable
    {
        yield 'price' => ['price[]=medium', '#filter-more-price-medium[checked]'];
        yield 'amenity' => ['amenity[]=overnight', '#filter-more-amenity-overnight[checked]'];
    }

    #[DataProvider('filterParams')]
    public function test_each_param_filters_and_renders_its_control_ticked(string $query, string $control): void
    {
        $this->eventVenue('Schloss Seeblick');
        $this->venue(['name' => 'Remise Anderswo'], static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Moderate));

        $crawler = $this->get('/hochzeitslocations?'.$query);

        self::assertSame(['Schloss Seeblick'], $this->names($crawler));
        self::assertCount(1, $crawler->filter($control));
        self::assertSame(['weitere Filter (1)'], $this->chips($crawler));
    }

    public function test_values_hide_one_by_one(): void
    {
        $venue = $this->venue([], static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Medium));
        $this->terms($venue, [[TermGroup::Amenity, 'overnight']]);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame(['medium'], $this->values($crawler, 'price[]'));
        self::assertSame(['overnight'], $this->values($crawler, 'amenity[]'));
        self::assertSame(['Preisniveau', 'Ausstattung'], $this->legends($crawler->filter('#filter-more')));
    }

    /**
     * @return iterable<string, array{PriceLevel|null, list<array{TermGroup, string}>, list<string>}>
     */
    public static function oneSection(): iterable
    {
        yield 'a price level only' => [PriceLevel::Medium, [], ['Preisniveau']];
        yield 'an amenity only' => [null, [[TermGroup::Amenity, 'overnight']], ['Ausstattung']];
    }

    /**
     * @param list<array{TermGroup, string}> $terms
     * @param list<string>                   $legends
     */
    #[DataProvider('oneSection')]
    public function test_a_section_without_values_disappears_with_its_legend(?PriceLevel $price, array $terms, array $legends): void
    {
        $venue = $this->venue([], null === $price ? null : static fn (Venue $venue) => $venue->changePriceLevel($price));
        $this->terms($venue, $terms);

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame($legends, $this->legends($crawler->filter('#filter-more')));
        self::assertCount(2, $crawler->filter('.filter-more__group'), 'Name and the event group.');
    }

    public function test_without_facts_the_group_is_absent_and_only_the_name_shows(): void
    {
        $venue = $this->venue();
        $this->terms($venue, [[TermGroup::Amenity, 'tent'], [TermGroup::Catering, 'vegan'], [TermGroup::SuitableFor, 'seminar']]);

        $crawler = $this->get('/hochzeitslocations');

        self::assertCount(0, $crawler->filter('#filter-more fieldset'));
        self::assertCount(1, $crawler->filter('.filter-more__group'), 'No empty group renders.');
        self::assertCount(1, $crawler->filter('#filter-more-q'));
    }

    public function test_active_values_without_data_stay_ticked_and_are_no_hidden_inputs(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations?price[]=medium&amenity[]=overnight');

        foreach (['price-medium', 'amenity-overnight'] as $id) {
            self::assertCount(1, $crawler->filter('#filter-more-'.$id.'[checked]'), $id);
        }
        self::assertSame([], $this->names($crawler));
        self::assertCount(0, $crawler->filter('#filter-more input[type="hidden"]'));
        self::assertSame(['weitere Filter (2)'], $this->chips($crawler));
    }

    public function test_codes_without_a_control_change_neither_results_nor_the_pill(): void
    {
        $venue = $this->venue(['name' => 'Schloss Seeblick']);
        $this->terms($venue, [[TermGroup::Amenity, 'tent']]);
        $this->venue(['name' => 'Remise Anderswo']);

        $crawler = $this->get('/hochzeitslocations?amenity[]=tent');

        self::assertCount(2, $crawler->filter('.card'));
        self::assertSame([], $this->chips($crawler));
        self::assertCount(0, $crawler->filter('#filter-more-amenity-tent'));
        self::assertSame('/hochzeitslocations', $crawler->filter('#filter-more a[data-overlay-clear]')->attr('href'));
    }

    public function test_the_retired_wedding_parameters_change_neither_results_nor_the_pill(): void
    {
        $this->eventVenue('Schloss Seeblick');
        $this->venue(['name' => 'Remise Anderswo']);

        $crawler = $this->get('/hochzeitslocations?curfew=none&ceremony[]=chapel&usage[]=exclusive');

        self::assertCount(2, $crawler->filter('.card'));
        self::assertSame([], $this->chips($crawler));
        self::assertCount(0, $crawler->filter('#filter-more-curfew-none, #filter-more-ceremony-chapel, #filter-more-usage-exclusive'));
        self::assertSame([], $this->hiddenState($crawler));
    }

    public function test_the_group_comes_first_after_the_name(): void
    {
        $venue = $this->eventVenue('Schloss Seeblick');
        $this->terms($venue, [[TermGroup::Setting, 'lake'], [TermGroup::Infrastructure, 'air_conditioning']]);

        $crawler = $this->get('/hochzeitslocations');

        $groups = $crawler->filter('.filter-more__group');
        self::assertCount(4, $groups);
        self::assertCount(1, $groups->eq(0)->filter('#filter-more-q'));
        self::assertSame(['Preisniveau', 'Ausstattung'], $this->legends($groups->eq(1)));
        self::assertCount(1, $crawler->filter('.filter-more__group--event'));
        self::assertSame(['Lage'], $this->legends($groups->eq(2)));
        self::assertSame(['Infrastruktur'], $this->legends($groups->eq(3)));
    }

    public function test_the_amenities_follow_the_vocabulary_order_and_come_after_the_prices(): void
    {
        $venue = $this->eventVenue('Schloss Seeblick');
        $this->terms($venue, [[TermGroup::Amenity, 'wheelchair_access'], [TermGroup::Amenity, 'dance_floor']]);
        $this->venue([], static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Exclusive));
        $this->venue([], static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Moderate));

        $crawler = $this->get('/hochzeitslocations');

        self::assertSame(
            ['amenity[]=overnight', 'amenity[]=dance_floor', 'amenity[]=wheelchair_access'],
            $crawler->filter('#filter-more fieldset')->last()->filter('input')->each(
                static fn (Crawler $input): string => $input->attr('name').'='.$input->attr('value'),
            ),
        );
        self::assertSame('Übernachtung', $crawler->filter('label[for="filter-more-amenity-overnight"]')->text());
        self::assertSame(['Moderat', 'Mittel', 'Exklusiv'], $crawler->filter('#filter-more input[name="price[]"] + label')->each(
            static fn (Crawler $label): string => $label->text(),
        ));
    }

    public function test_the_pill_counts_params_and_its_links_drop_both(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations?canton=zh&price[]=medium&price[]=moderate');
        self::assertSame(['Zürich', 'weitere Filter (1)'], $this->chips($crawler));

        $crawler = $this->get('/hochzeitslocations?canton=zh&price[]=medium&amenity[]=overnight');
        self::assertSame(['Zürich', 'weitere Filter (2)'], $this->chips($crawler));
        self::assertSame('/hochzeitslocations?canton=zh', $crawler->filter('.chip--more a')->attr('href'));
        self::assertSame('/hochzeitslocations?canton=zh', $crawler->filter('#filter-more a[data-overlay-clear]')->attr('href'));
    }

    public function test_the_region_form_carries_them_as_hidden_state(): void
    {
        $this->venue();

        $crawler = $this->get('/hochzeitslocations?price[]=medium&amenity[]=overnight');

        self::assertSame(['amenity[]=overnight', 'price[]=medium'], $this->hiddenState($crawler));
    }

    public function test_the_live_count_equals_the_rendered_total(): void
    {
        $this->eventVenue('Schloss Seeblick');
        $this->eventVenue('Gut Seeblick');
        $this->venue(['name' => 'Remise Anderswo']);
        $query = 'price[]=medium&price[]=exclusive&amenity[]=overnight';

        $total = $this->get('/hochzeitslocations?'.$query)->filter('.card')->count();
        $this->client->request('GET', '/_search/count?'.$query);

        self::assertSame(2, $total);
        self::assertSame(['count' => 2], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function test_a_card_shows_its_price_and_ambience_as_tags(): void
    {
        $ambience = str_repeat('Romantisch ', 5).'ländl';
        self::assertSame(60, mb_strlen($ambience));
        $this->venue(
            ['name' => 'Schloss Beides'],
            static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Medium),
            static fn (VenueTranslation $translation) => $translation->describeEvent($ambience, null, null),
        );
        $this->venue(['name' => 'Gut Preis'], static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Exclusive));
        $this->venue(['name' => 'Remise Stimmung'], null, static fn (VenueTranslation $translation) => $translation->describeEvent('Rustikal', null, null));
        $this->venue(['name' => 'Scheune Ohne']);

        $cards = [];
        foreach ($this->get('/hochzeitslocations')->filter('.card') as $node) {
            $card = new Crawler($node);
            $cards[$card->filter('.card__link')->text()] = $card;
        }

        self::assertSame(['Mittel', $ambience], $this->tags($cards['Schloss Beides']));
        self::assertSame('Preisniveau:', $cards['Schloss Beides']->filter('.card__tags li')->first()->filter('.visually-hidden')->text());
        self::assertSame(['Exklusiv'], $this->tags($cards['Gut Preis']));
        self::assertSame(['Rustikal'], $this->tags($cards['Remise Stimmung']));
        self::assertCount(0, $cards['Remise Stimmung']->filter('.card__tags .visually-hidden'), 'Only the price gets the prefix.');
        self::assertCount(0, $cards['Scheune Ohne']->filter('.card__tags'));
        self::assertCount(1, $cards['Schloss Beides']->filter('.card__summary + .card__tags'));
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
     * @return list<string>
     */
    private function tags(Crawler $card): array
    {
        return $card->filter('.card__tags .tag')->each(static fn (Crawler $tag): string => $tag->text());
    }

    /**
     * @return list<string>
     */
    private function legends(Crawler $crawler): array
    {
        return $crawler->filter('legend')->each(static fn (Crawler $legend): string => $legend->text());
    }

    /**
     * @return list<string>
     */
    private function values(Crawler $crawler, string $name): array
    {
        return $crawler->filter(\sprintf('#filter-more input[name="%s"]', $name))->each(
            static fn (Crawler $input): string => (string) $input->attr('value'),
        );
    }

    /**
     * The region form's hidden inputs that carry the other filters (not its own region values).
     *
     * @return list<string>
     */
    private function hiddenState(Crawler $crawler): array
    {
        return $crawler->filter('#filter-region-form input[type="hidden"]:not([data-region-value])')->each(
            static fn (Crawler $input): string => $input->attr('name').'='.$input->attr('value'),
        );
    }

    /**
     * A published venue with a German translation.
     *
     * @param array<string, mixed>                    $attributes
     * @param (callable(Venue): void)|null            $change     applied before the venue is stored
     * @param (callable(VenueTranslation): void)|null $describe   applied to the German translation
     */
    private function venue(array $attributes = [], ?callable $change = null, ?callable $describe = null): Venue
    {
        $factory = VenueFactory::new();
        $venue = (null === $change ? $factory : $factory->afterInstantiate($change(...)))->create($attributes);
        $translation = VenueTranslationFactory::new();
        (null === $describe ? $translation : $translation->afterInstantiate($describe(...)))->create(['venue' => $venue]);

        return $venue;
    }

    /**
     * A venue that matches one value of every parameter of the group: a medium price and the amenity "overnight".
     */
    private function eventVenue(string $name): Venue
    {
        $venue = $this->venue(['name' => $name], static fn (Venue $venue) => $venue->changePriceLevel(PriceLevel::Medium));
        $this->terms($venue, [[TermGroup::Amenity, 'overnight']]);

        return $venue;
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
