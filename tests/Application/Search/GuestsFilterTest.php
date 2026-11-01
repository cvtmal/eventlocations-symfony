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
use Zenstruck\Foundry\Test\Factories;

/**
 * The guests overlay (#11, FI-3): the number input form, the nine bubbles in their own form, the chip and the
 * filter itself. Own venues on the test's connection, rolled back afterwards.
 */
final class GuestsFilterTest extends WebTestCase
{
    use Factories;
    use LandingFixtures;

    private const array PICKS = ['20', '30', '50', '80', '100', '150', '200', '350', '500'];

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

    public function test_guests_narrow_the_listing(): void
    {
        $this->venue('Saal Hundert', null, 100);
        $this->venue('Stube Neunundneunzig', null, 99);

        self::assertSame(['Saal Hundert'], $this->names($this->get('/hochzeitslocations?guests=100')));
    }

    public function test_capacity_min_excludes_a_venue_above_the_guest_count(): void
    {
        $this->venue('Halle Gross', 150, 200);
        $this->venue('Scheune Passend', 80, 200);

        self::assertSame(['Scheune Passend'], $this->names($this->get('/hochzeitslocations?guests=120')));
    }

    public function test_venues_without_a_minimum_match_on_the_maximum(): void
    {
        $this->venue('Gut Ohne Minimum', null, 300);

        self::assertSame(['Gut Ohne Minimum'], $this->names($this->get('/hochzeitslocations?guests=280')));
    }

    public function test_the_count_endpoint_agrees_with_the_listing(): void
    {
        $this->venue('Saal Hundert', null, 100);
        $this->venue('Halle Gross', 150, 200);
        $this->venue('Gut Ohne Minimum', null, 300);

        $total = $this->get('/hochzeitslocations?guests=120')->filter('.card')->count();
        $this->client->request('GET', '/_search/count?guests=120');

        self::assertSame(1, $total);
        self::assertSame(['count' => 1], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function chipLabels(): iterable
    {
        yield 'plural' => ['120', '120 Gäste'];
        yield 'singular' => ['1', '1 Gast'];
        yield 'grouped' => ['1500', "1'500 Gäste"];
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
        self::assertSame('', $crawler->filter('#filter-guests-input')->attr('value'));
        self::assertCount(0, $crawler->filter('.guests-bubble[aria-pressed="true"]'));
    }

    public function test_overlay_is_a_get_form_with_the_live_count_contract(): void
    {
        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=120');

        $overlay = $crawler->filter('#filter-guests');
        self::assertSame('Wie viele Gäste erwarten Sie?', $overlay->filter('h2')->text());

        $form = $crawler->filter('#filter-guests-form');
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/hochzeitslocations', $form->attr('action'));
        self::assertSame('/_search/count?', $form->attr('data-live-count-url'));
        self::assertNotNull($form->attr('data-live-count'));
        self::assertNotNull($form->attr('data-overlay-form'));

        $input = $form->filter('input[name="guests"]');
        self::assertSame(
            ['number', '1', '50000', '1', 'numeric', '120'],
            [$input->attr('type'), $input->attr('min'), $input->attr('max'), $input->attr('step'), $input->attr('inputmode'), $input->attr('value')],
        );
        self::assertSame('Anzahl Gäste', $crawler->filter('label[for="filter-guests-input"]')->text());

        $submit = $overlay->filter('.overlay__footer button[type="submit"]');
        self::assertSame('filter-guests-form', $submit->attr('form'));
        self::assertNotNull($submit->attr('data-live-count-label'));
        foreach (['data-count-zero', 'data-count-one', 'data-count-other'] as $attribute) {
            self::assertNotSame('', (string) $submit->attr($attribute), $attribute);
        }
        self::assertStringContainsString('%n', (string) $submit->attr('data-count-other'));

        self::assertSame('/hochzeitslocations?canton=zh', $overlay->filter('.overlay__footer a[data-overlay-clear]')->attr('href'));
    }

    public function test_bubbles_are_nine_submit_buttons_in_ascending_order(): void
    {
        $bubbles = $this->get('/hochzeitslocations')->filter('form.guests-bubbles button');

        self::assertSame(self::PICKS, $bubbles->each(static fn (Crawler $bubble): string => (string) $bubble->attr('value')));
        self::assertSame(self::PICKS, $bubbles->each(static fn (Crawler $bubble): string => $bubble->text()));
        self::assertSame(
            array_fill(0, 9, 'submit guests'),
            $bubbles->each(static fn (Crawler $bubble): string => $bubble->attr('type').' '.$bubble->attr('name')),
        );
        self::assertSame('Gästezahl wählen', $this->get('/hochzeitslocations')->filter('form.guests-bubbles')->attr('aria-label'));
    }

    public function test_only_the_matching_bubble_is_pressed(): void
    {
        $pressed = $this->get('/hochzeitslocations?guests=150')->filter('.guests-bubble[aria-pressed="true"]');
        self::assertSame(['150'], $pressed->each(static fn (Crawler $bubble): string => (string) $bubble->attr('value')));
        self::assertCount(8, $this->get('/hochzeitslocations?guests=150')->filter('.guests-bubble[aria-pressed="false"]'));

        self::assertCount(0, $this->get('/hochzeitslocations?guests=120')->filter('.guests-bubble[aria-pressed="true"]'));
    }

    public function test_enter_cannot_reach_a_bubble(): void
    {
        $crawler = $this->get('/hochzeitslocations');

        // A submit button inside the input's form would be its default button (D1).
        self::assertCount(0, $crawler->filter('#filter-guests-form button'));
        self::assertCount(0, $crawler->filter('.guests-bubble[form]'));
        self::assertCount(0, $crawler->filter('form form'));
    }

    public function test_both_forms_keep_the_other_filters(): void
    {
        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=150&setting[]=lake&page=1');

        foreach (['#filter-guests-form', 'form.guests-bubbles'] as $form) {
            self::assertSame(
                ['canton=zh', 'setting[]=lake'],
                $this->hiddenState($crawler, $form),
                $form,
            );
        }
    }

    public function test_both_forms_carry_the_wedding_filters(): void
    {
        $crawler = $this->get('/hochzeitslocations?price[]=medium&curfew=none&ceremony[]=chapel&amenity[]=overnight&usage[]=exclusive&guests=80');

        foreach (['#filter-guests-form', 'form.guests-bubbles'] as $form) {
            self::assertSame(
                ['ceremony[]=chapel', 'amenity[]=overnight', 'usage[]=exclusive', 'price[]=medium', 'curfew=none'],
                $this->hiddenState($crawler, $form),
                $form,
            );
        }
    }

    public function test_a_bubble_submit_applies_its_value(): void
    {
        $crawler = $this->get('/hochzeitslocations?canton=zh&guests=80');

        $form = $crawler->filter('form.guests-bubbles button[value="150"]')->form();
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        parse_str((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_QUERY), $query);
        self::assertSame(['canton' => 'zh', 'guests' => '150'], $query);
    }

    public function test_both_forms_keep_a_canton_landing_path(): void
    {
        $this->inCanton(Canton::ZH, 5);

        $crawler = $this->get('/hochzeitslocations/zuerich?guests=80&setting[]=lake');

        // listing_form() (#17): the path facet stays in the action and out of the hidden state and the count query.
        foreach (['#filter-guests-form', 'form.guests-bubbles'] as $form) {
            self::assertSame('/hochzeitslocations/zuerich', $crawler->filter($form)->attr('action'), $form);
            self::assertSame(['setting[]=lake'], $this->hiddenState($crawler, $form), $form);
        }
        self::assertSame('/_search/count?canton=zh&', $crawler->filter('#filter-guests-form')->attr('data-live-count-url'));

        $this->client->submit($crawler->filter('form.guests-bubbles button[value="150"]')->form());

        self::assertResponseIsSuccessful();
        $uri = (string) $this->client->getRequest()->getUri();
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        self::assertSame('/hochzeitslocations/zuerich', parse_url($uri, \PHP_URL_PATH));
        self::assertSame(['guests' => '150', 'setting' => ['lake']], $query);
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
