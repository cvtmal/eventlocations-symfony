<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Support\Search\LandingFixtures;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\InputFormField;
use Symfony\Component\DomCrawler\Form;
use Zenstruck\Foundry\Test\Factories;

/**
 * The guests overlay (#11, FI-3; F5 `filter-event`): one live-count form with a number input, the chip and the filter
 * itself. A venue matches guests=N when it holds N (capacity_max) and states no higher minimum (capacity_min), or
 * when one of its rooms does (F5b). Own venues on the test's connection, rolled back afterwards.
 */
final class GuestsFilterTest extends WebTestCase
{
    use Factories;
    use LandingFixtures;

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

    public function test_the_number_narrows_the_listing_on_capacity_max(): void
    {
        $this->venue('Stube Vierzig', null, 40);
        $this->venue('Saal Hundertzwanzig', null, 120);
        $this->venue('Halle Vierhundert', null, 400);

        self::assertSame(['Halle Vierhundert', 'Saal Hundertzwanzig'], $this->sortedNames($this->get('/eventlokale?guests=120')));
        self::assertSame(['Halle Vierhundert'], $this->names($this->get('/eventlokale?guests=121')));
        self::assertCount(3, $this->get('/eventlokale?guests=40')->filter('.card'));
    }

    public function test_a_higher_capacity_min_excludes_the_venue(): void
    {
        $this->venue('Halle Gross', 150, 400);
        $this->venue('Scheune Klein', 20, 140);
        $this->venue('Saal Offen', null, 140);

        self::assertSame(['Saal Offen', 'Scheune Klein'], $this->sortedNames($this->get('/eventlokale?guests=30')));
        self::assertSame(['Saal Offen', 'Scheune Klein'], $this->sortedNames($this->get('/eventlokale?guests=140')));
        self::assertSame(['Halle Gross'], $this->names($this->get('/eventlokale?guests=150')));
        self::assertSame(['Halle Gross'], $this->names($this->get('/eventlokale?guests=400')));
        self::assertSame(['Saal Offen'], $this->names($this->get('/eventlokale?guests=10')));
    }

    public function test_the_count_endpoint_agrees_with_the_listing(): void
    {
        $this->venue('Saal Hundert', null, 100);
        $this->venue('Halle Hundertzwanzig', 110, 120);
        $this->venue('Gut Dreihundert', null, 300);

        $total = $this->get('/eventlokale?guests=105')->filter('.card')->count();
        $this->client->request('GET', '/_search/count?guests=105');

        self::assertSame(1, $total);
        self::assertSame(['count' => 1], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function test_a_room_that_fits_lists_the_venue_and_its_card_shows_the_room_maximum(): void
    {
        RoomFactory::createOne(['venue' => $this->venue('Halle Mit Saal', 20, 120), 'capacityMin' => null, 'capacityMax' => 300]);
        RoomFactory::createOne(['venue' => $this->venue('Stube Mit Zimmer', 10, 40), 'capacityMin' => 200, 'capacityMax' => 260]);
        $this->venue('Saal Ohne Raum', null, 120);

        $crawler = $this->get('/eventlokale?guests=250');
        $this->client->request('GET', '/_search/count?guests=250');

        self::assertSame(['Halle Mit Saal', 'Stube Mit Zimmer'], $this->sortedNames($crawler));
        self::assertSame(['count' => 2], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(['Halle Mit Saal' => '300', 'Stube Mit Zimmer' => '10 - 260'], $this->capacities($crawler));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function chipLabels(): iterable
    {
        yield 'one' => ['1', '1 Gast'];
        yield 'eighty' => ['80', '80 Gäste'];
        yield 'grouped' => ['1500', "1'500 Gäste"];
        yield 'maximum' => ['50000', "50'000 Gäste"];
        yield 'leading zero' => ['080', '80 Gäste'];
    }

    #[DataProvider('chipLabels')]
    public function test_chip_label(string $guests, string $label): void
    {
        $crawler = $this->get('/eventlokale?canton=zh&guests='.$guests);

        $chip = $crawler->filter('.filter-bar .chip')->reduce(static fn (Crawler $chip): bool => $chip->text() === $label);
        self::assertCount(1, $chip);
        self::assertSame('/eventlokale?canton=zh', $chip->attr('href'));
        self::assertSame('Filter '.$label.' entfernen', $chip->attr('aria-label'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidGuests(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'text' => ['abc'];
        yield 'decimal' => ['80.5'];
        yield 'exponent' => ['1e3'];
        yield 'above the maximum' => ['50001'];
        yield 'too many digits' => ['000080'];
        yield 'array' => ['[]=80'];
    }

    #[DataProvider('invalidGuests')]
    public function test_invalid_guests_are_dropped(string $guests): void
    {
        $this->venue('Stube Vierzig', null, 40);
        $query = str_starts_with($guests, '[]') ? 'guests'.$guests : 'guests='.$guests;

        $crawler = $this->get('/eventlokale?'.$query);

        self::assertCount(0, $crawler->filter('.filter-bar .chip'));
        self::assertSame('', $crawler->filter('#filter-guests-input')->attr('value'));
        self::assertCount(1, $crawler->filter('.card'));
        self::assertSame('http://localhost/eventlokale', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function test_overlay_is_one_get_form_with_the_live_count_contract(): void
    {
        $crawler = $this->get('/eventlokale?canton=zh&guests=120');

        $overlay = $crawler->filter('#filter-guests');
        self::assertSame('Wie viele Gäste erwartest du?', $overlay->filter('h2')->text());
        self::assertCount(1, $overlay->filter('form'));
        self::assertSame('Anzahl Gäste', $crawler->filter('.filter-bar [popovertarget="filter-guests"]:not([popovertargetaction])')->text());

        $form = $crawler->filter('form#filter-guests-form.guests-filter');
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/eventlokale', $form->attr('action'));
        self::assertSame('off', $form->attr('autocomplete'));
        self::assertSame('/_search/count?', $form->attr('data-live-count-url'));
        self::assertNotNull($form->attr('data-live-count'));
        self::assertNotNull($form->attr('data-overlay-form'));

        $submit = $overlay->filter('.overlay__footer button[type="submit"]');
        self::assertSame('filter-guests-form', $submit->attr('form'));
        self::assertNotNull($submit->attr('data-live-count-label'));
        self::assertSame('Keine Resultate', $submit->attr('data-count-zero'));
        self::assertSame('1 Resultat anzeigen', $submit->attr('data-count-one'));
        self::assertSame('%n Resultate anzeigen', $submit->attr('data-count-other'));

        self::assertSame('/eventlokale?canton=zh', $overlay->filter('.overlay__footer a[data-overlay-clear]')->attr('href'));
        self::assertSame('Filter löschen', $overlay->filter('.overlay__footer a[data-overlay-clear]')->text());
    }

    public function test_one_number_input_and_no_bands(): void
    {
        $form = $this->get('/eventlokale')->filter('#filter-guests-form');

        $input = $form->filter('input:not([type="hidden"])');
        self::assertCount(1, $input);
        self::assertSame('filter-guests-input', $input->attr('id'));
        self::assertSame('guests', $input->attr('name'));
        self::assertSame('number', $input->attr('type'));
        self::assertSame('z.B. 80', $input->attr('placeholder'));
        self::assertSame('', $input->attr('value'));
        // The browser refuses what the codec would drop, so the live count never counts an ignored value.
        self::assertSame(['1', '50000', '1', 'numeric'], [$input->attr('min'), $input->attr('max'), $input->attr('step'), $input->attr('inputmode')]);
        self::assertSame('Anzahl Gäste', $form->filter('label[for="filter-guests-input"]')->text());
        self::assertCount(0, $form->filter('label[for="filter-guests-input"].visually-hidden'), 'The label is visible.');
        self::assertCount(0, $form->filter('input[type="radio"], select, fieldset'));
    }

    public function test_the_input_is_prefilled_from_the_criteria(): void
    {
        self::assertSame('80', $this->get('/eventlokale?guests=80')->filter('#filter-guests-input')->attr('value'));
        self::assertSame('80', $this->get('/eventlokale?guests=080')->filter('#filter-guests-input')->attr('value'));
    }

    public function test_enter_reaches_only_the_footer_submit(): void
    {
        $crawler = $this->get('/eventlokale');

        self::assertCount(0, $crawler->filter('#filter-guests-form button, #filter-guests-form input[type="submit"]'));
        self::assertCount(1, $crawler->filter('[type="submit"][form="filter-guests-form"]'));
        self::assertCount(0, $crawler->filter('form form'));
    }

    public function test_the_form_keeps_the_other_filters(): void
    {
        $crawler = $this->get('/eventlokale?canton=zh&guests=150&setting[]=lake&page=1');

        self::assertSame(['canton=zh', 'setting[]=lake'], $this->hiddenState($crawler, '#filter-guests-form'));
    }

    public function test_the_form_carries_the_event_filters(): void
    {
        $crawler = $this->get('/eventlokale?catering[]=buffet&amenity[]=overnight&event[]=wedding&event[]=apero&guests=100');

        self::assertSame(
            ['event[]=apero', 'event[]=wedding', 'catering[]=buffet', 'amenity[]=overnight'],
            $this->hiddenState($crawler, '#filter-guests-form'),
        );
    }

    public function test_a_submit_applies_the_number(): void
    {
        $this->venue('Saal Achtzig', null, 80);
        $this->venue('Halle Vierhundert', null, 400);

        $crawler = $this->get('/eventlokale?setting[]=lake&guests=150');
        $form = $crawler->filter('#filter-guests-form')->form();
        $this->setGuests($form, '100');
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        parse_str((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_QUERY), $query);
        self::assertEqualsCanonicalizing(['setting' => ['lake'], 'guests' => '100'], $query);
        self::assertSame(['100 Gäste', 'weitere Filter (1)'], $this->chips($crawler));
        self::assertSame('100', $crawler->filter('#filter-guests-input')->attr('value'));
    }

    public function test_an_emptied_input_drops_the_filter(): void
    {
        $this->venue('Saal Achtzig', null, 80);
        $this->venue('Halle Vierhundert', null, 400);

        $form = $this->get('/eventlokale?guests=150')->filter('#filter-guests-form')->form();
        $this->setGuests($form, '');
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->chips($crawler));
        self::assertCount(2, $crawler->filter('.card'));
    }

    public function test_the_form_keeps_a_canton_landing_path(): void
    {
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->get('/eventlokale/zuerich?guests=100&setting[]=lake');

        // listing_form() (#17): the path facet stays in the action and out of the hidden state and the count query.
        self::assertSame('/eventlokale/zuerich', $crawler->filter('#filter-guests-form')->attr('action'));
        self::assertSame(['setting[]=lake'], $this->hiddenState($crawler, '#filter-guests-form'));
        self::assertSame('/_search/count?canton=zh&', $crawler->filter('#filter-guests-form')->attr('data-live-count-url'));

        $form = $crawler->filter('#filter-guests-form')->form();
        $this->setGuests($form, '150');
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $uri = (string) $this->client->getRequest()->getUri();
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        self::assertSame('/eventlokale/zuerich', parse_url($uri, \PHP_URL_PATH));
        self::assertEqualsCanonicalizing(['setting' => ['lake'], 'guests' => '150'], $query);
    }

    public function test_the_listing_loads_no_guests_script(): void
    {
        self::assertCount(0, $this->get('/eventlokale')->filter('script[src*="guests-filter"]'));
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
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('.card__link')->each(static fn (Crawler $link): string => $link->text());
    }

    /**
     * The card names sorted, because the listing's daily order seed is not the point here.
     *
     * @return list<string>
     */
    private function sortedNames(Crawler $crawler): array
    {
        $names = $this->names($crawler);
        sort($names);

        return $names;
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
    private function hiddenState(Crawler $crawler, string $form): array
    {
        return $crawler->filter($form.' input[type="hidden"]')->each(
            static fn (Crawler $input): string => $input->attr('name').'='.$input->attr('value'),
        );
    }

    /**
     * The first facts item (the capacity line) per card name, sorted by name.
     *
     * @return array<string, string>
     */
    private function capacities(Crawler $crawler): array
    {
        $capacities = [];
        $crawler->filter('.card')->each(static function (Crawler $card) use (&$capacities): void {
            $capacities[$card->filter('.card__link')->text()] = $card->filter('.card-facts__item')->first()->text();
        });
        ksort($capacities);

        return $capacities;
    }

    private function setGuests(Form $form, string $value): void
    {
        $field = $form['guests'];
        self::assertInstanceOf(InputFormField::class, $field);
        $field->setValue($value);
    }

    private function venue(string $name, ?int $min, int $max): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'capacityMin' => $min, 'capacityMax' => $max]);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }
}
