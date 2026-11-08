<?php

declare(strict_types=1);

namespace App\Tests\Application\Favorites;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;

/**
 * `/merkliste` (MK-3) with valid, stale and garbage cookie values. The client shares the test's
 * connection (no reboot), so the venues created here are visible and rolled back afterwards.
 */
final class MerklistePageTest extends WebTestCase
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

    public function test_without_a_cookie_shows_the_empty_state(): void
    {
        $this->venue('Gasthof Linde');

        $crawler = $this->get();

        self::assertSelectorTextSame('.merkliste__head h1[data-merkliste-title]', 'Deine Merkliste');
        self::assertSelectorNotExists('[data-merkliste-empty][hidden]');
        self::assertSelectorTextContains('[data-merkliste-empty]', 'Deine Merkliste ist noch leer.');
        self::assertSame('/eventlokale', $crawler->filter('[data-merkliste-empty] a')->attr('href'));
        self::assertSelectorExists('[data-merkliste-empty] .listing-empty__disc');
        self::assertSelectorExists('[data-merkliste-total][hidden]');
        self::assertCount(0, $crawler->filter('.card'));
        self::assertNoCookieSent($this->client);
    }

    public function test_lists_the_cards_in_saved_order(): void
    {
        $first = $this->venue('Erste Location');
        $second = $this->venue('Zweite Location');
        $third = $this->venue('Dritte Location');
        $this->seed($third->number().','.$first->number().','.$second->number());

        $crawler = $this->get();

        self::assertSame(['Dritte Location', 'Erste Location', 'Zweite Location'], $crawler->filter('.card__link')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSelectorTextSame('[data-merkliste-total]', '3 Locations');
        self::assertSelectorExists('[data-merkliste-empty][hidden]');
        self::assertNoCookieSent($this->client);
    }

    public function test_reissues_a_list_written_by_the_script(): void
    {
        $first = $this->venue('Erste Location')->number();
        $second = $this->venue('Zweite Location')->number();
        $this->seed($first.','.$second.',');

        $crawler = $this->get();

        self::assertCount(2, $crawler->filter('.card'));
        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame($first.','.$second, $cookies[0]->getValue());
    }

    public function test_a_single_entry_reads_in_the_singular(): void
    {
        $this->seed((string) $this->venue('Einzelstück')->number());

        $this->get();

        self::assertSelectorTextSame('[data-merkliste-total]', '1 Location');
    }

    public function test_each_card_has_a_remove_form_and_no_merken_button(): void
    {
        $venue = $this->venue('Schloss Seeburg');
        $this->seed((string) $venue->number());

        $crawler = $this->get();

        $form = $crawler->filter(\sprintf('.card form.merkliste-remove[method="post"][action="/merkliste/%d"]', $venue->number()));
        self::assertCount(1, $form);
        self::assertCount(1, $form->filter('input[name="_token"][value="csrf-token"]'));
        self::assertCount(1, $form->filter('input[name="intent"][value="remove"]'));
        $button = $form->filter(\sprintf('button[type="submit"][data-merkliste-remove="%d"]', $venue->number()));
        self::assertSame('Schloss Seeburg entfernen', $button->attr('aria-label'));
        self::assertSame('Schloss Seeburg', $button->attr('data-merken-name'));
        self::assertSame('Entfernen', $button->text());
        self::assertCount(0, $crawler->filter('.card [data-merken]'));
    }

    /**
     * G14: the badge «Kurzfristig frei» sits on every card surface, the Merkliste's `cardsByNumbers()` included.
     */
    public function test_a_pro_venue_with_a_live_offer_carries_the_last_minute_badge(): void
    {
        $venue = $this->venue('Schloss Seeburg', ['tier' => KnownTiers::PRO]);
        LastMinuteOfferFactory::createOne(['venue' => $venue]);
        $this->seed((string) $venue->number());

        $crawler = $this->get();

        self::assertSame(['Top-Eintrag', 'Kurzfristig frei'], $crawler->filter('.card .badge')->each(static fn (Crawler $badge): string => $badge->text()));
    }

    public function test_the_strings_for_the_script_are_on_the_page(): void
    {
        $this->seed((string) $this->venue('Eine Location')->number());

        $crawler = $this->get();

        $total = $crawler->filter('[data-merkliste-total]');
        self::assertSame('1 Location', $total->attr('data-count-one'));
        self::assertSame('__count__ Locations', $total->attr('data-count-other'));
        self::assertCount(1, $crawler->filter('[data-merkliste-title][tabindex="-1"]'));
    }

    public function test_prunes_stale_numbers_and_garbage(): void
    {
        $first = $this->venue('Bleibt')->number();
        $second = $this->venue('Bleibt auch')->number();
        $this->seed(\sprintf('abc,%d,%d,0,99999999999,2147483647,%d', $first, $first, $second));

        $crawler = $this->get();

        self::assertCount(2, $crawler->filter('.card'));
        self::assertSame($first.','.$second, $this->jar());
        self::assertStringContainsString(\sprintf('merkliste=%d%%2C%d', $first, $second), (string) $this->client->getResponse()->headers->getCookies()[0]);
    }

    public function test_deletes_the_cookie_when_nothing_is_left(): void
    {
        $this->seed('2147483647');

        $crawler = $this->get();

        self::assertCount(0, $crawler->filter('.card'));
        self::assertSelectorNotExists('[data-merkliste-empty][hidden]');
        self::assertStringContainsString('merkliste=deleted', (string) $this->client->getResponse()->headers->getCookies()[0]);
        self::assertNull($this->jar());
    }

    public function test_drops_and_prunes_an_unpublished_venue(): void
    {
        $published = $this->venue('Publiziert')->number();
        $draft = $this->venue('Entwurf', ['status' => VenueStatus::Unpublished])->number();
        $this->seed($draft.','.$published);

        $crawler = $this->get();

        self::assertSame(['Publiziert'], $crawler->filter('.card__link')->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame((string) $published, $this->jar());
    }

    public function test_an_array_shaped_cookie_counts_as_absent(): void
    {
        $response = $this->client->getKernel()->handle(Request::create('/merkliste', cookies: ['merkliste' => ['a' => '1']]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
    }

    public function test_is_private_and_not_indexed(): void
    {
        $this->get();

        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertSelectorExists('meta[name="description"][content]');
        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('private'));
        self::assertTrue($headers->hasCacheControlDirective('no-store'));
        self::assertFalse($headers->hasCacheControlDirective('public'));
        self::assertFalse($headers->hasCacheControlDirective('s-maxage'));
    }

    public function test_the_full_notice_shows_at_100_entries_only(): void
    {
        $numbers = [];
        for ($i = 1; $i <= 100; ++$i) {
            $numbers[] = $this->venue('Location '.$i)->number();
        }

        $this->seed(implode(',', \array_slice($numbers, 0, 99)));
        $this->get();
        self::assertSelectorExists('[data-merkliste-full][hidden]');

        $this->seed(implode(',', $numbers));
        $this->get();
        self::assertSelectorNotExists('[data-merkliste-full][hidden]');
        self::assertSelectorExists('[data-merkliste-full] .icon');
        self::assertSelectorTextContains('[data-merkliste-full]', 'Es passen 100 Locations hinein.');
        self::assertSelectorTextSame('[data-merkliste-total]', '100 Locations');
    }

    private function get(): Crawler
    {
        $crawler = $this->client->request('GET', '/merkliste');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function seed(string $value): void
    {
        $this->client->getCookieJar()->set(new Cookie('merkliste', $value, null, '/', 'localhost'));
    }

    private function jar(): ?string
    {
        return $this->client->getCookieJar()->get('merkliste')?->getValue();
    }

    private static function assertNoCookieSent(KernelBrowser $client): void
    {
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    /**
     * A published venue with a German translation.
     *
     * @param array<string, mixed> $attributes
     */
    private function venue(string $name, array $attributes = []): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name] + $attributes);
        VenueTranslationFactory::createOne(['venue' => $venue]);

        return $venue;
    }
}
