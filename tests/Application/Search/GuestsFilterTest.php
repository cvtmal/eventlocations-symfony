<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Zenstruck\Foundry\Test\Factories;

/**
 * The guests overlay (#11, #51, FI-3): one live-count form with four band radios on capacity_max, the chip and the
 * filter itself. Own venues on the test's connection, rolled back afterwards.
 */
final class GuestsFilterTest extends WebTestCase
{
    use Factories;
    use LandingFixtures;

    private const array BANDS = ['50', '100', '150', '151'];
    private const array LABELS = ['bis 50 Gäste', 'bis 100 Gäste', 'bis 150 Gäste', 'über 150 Gäste'];

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

    public function test_a_band_narrows_the_listing_on_capacity_max(): void
    {
        $this->venue('Stube Vierzig', null, 40);
        $this->venue('Saal Hundertzwanzig', null, 120);
        $this->venue('Halle Vierhundert', null, 400);

        self::assertSame(['Saal Hundertzwanzig'], $this->names($this->get('/hochzeitslocations?guests=150')));
    }

    public function test_a_large_venue_is_not_in_bis_50(): void
    {
        $this->venue('Stube Vierzig', null, 40);
        $this->venue('Halle Vierhundert', 10, 400);

        self::assertSame(['Stube Vierzig'], $this->names($this->get('/hochzeitslocations?guests=50')));
    }

    public function test_capacity_min_is_ignored(): void
    {
        $this->venue('Halle Gross', 150, 200);
        $this->venue('Scheune Klein', 120, 140);

        self::assertSame(['Halle Gross'], $this->names($this->get('/hochzeitslocations?guests=151')));
        self::assertSame(['Scheune Klein'], $this->names($this->get('/hochzeitslocations?guests=110')));
    }

    public function test_the_count_endpoint_agrees_with_the_listing(): void
    {
        $this->venue('Saal Hundert', null, 100);
        $this->venue('Halle Hundertzwanzig', 110, 120);
        $this->venue('Gut Dreihundert', null, 300);

        $total = $this->get('/hochzeitslocations?guests=150')->filter('.card')->count();
        $this->client->request('GET', '/_search/count?guests=150');

        self::assertSame(1, $total);
        self::assertSame(['count' => 1], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function chipLabels(): iterable
    {
        foreach (self::BANDS as $i => $band) {
            yield 'band '.$band => [$band, self::LABELS[$i]];
        }
        yield 'legacy 120' => ['120', 'bis 150 Gäste'];
        yield 'legacy 1' => ['1', 'bis 50 Gäste'];
        yield 'legacy 1500' => ['1500', 'über 150 Gäste'];
    }

    #[DataProvider('chipLabels')]
    public function test_chip_label(string $guests, string $label): void
    {
        $crawler = $this->get('/hochzeitslocations?canton=zh&guests='.$guests);

        $chip = $crawler->filter('.filter-bar .chip')->reduce(static fn (Crawler $chip): bool => $chip->text() === $label);
        self::assertCount(1, $chip);
        self::assertSame('/hochzeitslocations?canton=zh', $chip->attr('href') ?? $chip->filter('a')->attr('href'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidGuests(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'text' => ['abc'];
        yield 'exponent' => ['1e3'];
        yield 'above the maximum' => ['99999'];
    }

    #[DataProvider('invalidGuests')]
    public function test_invalid_guests_are_dropped(string $guests): void
    {
        $crawler = $this->get('/hochzeitslocations?guests='.$guests);

        self::assertCount(0, $crawler->filter('.filter-bar .chip'));
        self::assertCount(0, $crawler->filter('#filter-guests-form input[name="guests"][checked]'));
    }

    public function test_overlay_is_one_get_form_with_the_live_count_contract(): void
    {
        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=120');

        $overlay = $crawler->filter('#filter-guests');
        self::assertSame('Wie viele Gäste erwartest du?', $overlay->filter('h2')->text());
        self::assertCount(1, $overlay->filter('form'));

        $form = $crawler->filter('#filter-guests-form');
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/hochzeitslocations', $form->attr('action'));
        self::assertSame('/_search/count?', $form->attr('data-live-count-url'));
        self::assertNotNull($form->attr('data-live-count'));
        self::assertNotNull($form->attr('data-overlay-form'));

        self::assertCount(0, $crawler->filter('#filter-guests input[type="number"]'));
        self::assertCount(0, $crawler->filter('form.guests-bubbles'));
        self::assertCount(0, $crawler->filter('#filter-guests [aria-pressed]'));
        self::assertSame('Anzahl Gäste', $form->filter('fieldset.guests-bands > legend')->text());

        $submit = $overlay->filter('.overlay__footer button[type="submit"]');
        self::assertSame('filter-guests-form', $submit->attr('form'));
        self::assertNotNull($submit->attr('data-live-count-label'));
        foreach (['data-count-zero', 'data-count-one', 'data-count-other'] as $attribute) {
            self::assertNotSame('', (string) $submit->attr($attribute), $attribute);
        }
        self::assertStringContainsString('%n', (string) $submit->attr('data-count-other'));

        self::assertSame('/hochzeitslocations?canton=zh', $overlay->filter('.overlay__footer a[data-overlay-clear]')->attr('href'));
    }

    public function test_four_radios_in_ascending_order_with_their_labels(): void
    {
        $bubbles = $this->get('/hochzeitslocations')->filter('#filter-guests-form label.guests-bubble');

        self::assertSame(self::LABELS, $bubbles->each(static fn (Crawler $bubble): string => $bubble->text()));
        self::assertSame(
            array_map(static fn (string $band): string => 'radio guests '.$band, self::BANDS),
            $bubbles->each(static fn (Crawler $bubble): string => \sprintf(
                '%s %s %s',
                $bubble->filter('input')->attr('type'),
                $bubble->filter('input')->attr('name'),
                $bubble->filter('input')->attr('value'),
            )),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function checkedRadios(): iterable
    {
        yield 'none' => ['', []];
        yield 'band 150' => ['guests=150', ['150']];
        yield 'legacy 120' => ['guests=120', ['150']];
        yield 'legacy 1500' => ['guests=1500', ['151']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('checkedRadios')]
    public function test_only_the_matching_radio_is_checked(string $query, array $expected): void
    {
        $checked = $this->get('/hochzeitslocations?'.$query)->filter('#filter-guests-form input[name="guests"][checked]');

        self::assertSame($expected, $checked->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
    }

    public function test_enter_reaches_only_the_footer_submit(): void
    {
        $crawler = $this->get('/hochzeitslocations');

        self::assertCount(0, $crawler->filter('#filter-guests-form button, #filter-guests-form input[type="submit"]'));
        self::assertCount(1, $crawler->filter('[type="submit"][form="filter-guests-form"]'));
        self::assertCount(0, $crawler->filter('form form'));
    }

    public function test_the_form_keeps_the_other_filters(): void
    {
        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=150&setting[]=lake&page=1');

        self::assertSame(['canton=zh', 'setting[]=lake'], $this->hiddenState($crawler, '#filter-guests-form'));
    }

    public function test_the_form_carries_the_wedding_filters(): void
    {
        $crawler = $this->get('/hochzeitslocations?price[]=medium&curfew=none&ceremony[]=chapel&amenity[]=overnight&usage[]=exclusive&guests=100');

        self::assertSame(
            ['ceremony[]=chapel', 'amenity[]=overnight', 'usage[]=exclusive', 'price[]=medium', 'curfew=none'],
            $this->hiddenState($crawler, '#filter-guests-form'),
        );
    }

    public function test_a_radio_submit_applies_its_band(): void
    {
        $this->venue('Saal Achtzig', null, 80);
        $this->venue('Halle Vierhundert', null, 400);

        $crawler = $this->get('/hochzeitslocations?setting[]=lake&guests=150');
        $form = $crawler->filter('#filter-guests-form')->form();
        $radios = $form['guests'];
        self::assertInstanceOf(ChoiceFormField::class, $radios);
        $radios->select('100');
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        parse_str((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_QUERY), $query);
        // DomCrawler lists the radio before the hidden inputs; a browser keeps tree order. Order is not the point.
        self::assertEqualsCanonicalizing(['setting' => ['lake'], 'guests' => '100'], $query);
        self::assertCount(1, $crawler->filter('.filter-bar .chip')->reduce(static fn (Crawler $chip): bool => 'bis 100 Gäste' === $chip->text()));
    }

    public function test_the_form_keeps_a_canton_landing_path(): void
    {
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->get('/hochzeitslocations/zuerich?guests=100&setting[]=lake');

        // listing_form() (#17): the path facet stays in the action and out of the hidden state and the count query.
        self::assertSame('/hochzeitslocations/zuerich', $crawler->filter('#filter-guests-form')->attr('action'));
        self::assertSame(['setting[]=lake'], $this->hiddenState($crawler, '#filter-guests-form'));
        self::assertSame('/_search/count?canton=zh&', $crawler->filter('#filter-guests-form')->attr('data-live-count-url'));

        $form = $crawler->filter('#filter-guests-form')->form();
        $radios = $form['guests'];
        self::assertInstanceOf(ChoiceFormField::class, $radios);
        $radios->select('150');
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $uri = (string) $this->client->getRequest()->getUri();
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        self::assertSame('/hochzeitslocations/zuerich', parse_url($uri, \PHP_URL_PATH));
        self::assertEqualsCanonicalizing(['setting' => ['lake'], 'guests' => '150'], $query);
    }

    public function test_the_listing_loads_no_guests_script(): void
    {
        self::assertCount(0, $this->get('/hochzeitslocations')->filter('script[src*="guests-filter"]'));
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
     * @return list<string>
     */
    private function hiddenState(Crawler $crawler, string $form): array
    {
        return $crawler->filter($form.' input[type="hidden"]')->each(
            static fn (Crawler $input): string => $input->attr('name').'='.$input->attr('value'),
        );
    }

    private function venue(string $name, ?int $min, int $max): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'capacityMin' => $min, 'capacityMax' => $max]);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }
}
