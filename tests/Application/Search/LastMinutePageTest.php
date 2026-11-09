<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\LastMinuteOfferFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The page «Kurzfristig frei» (G14, D55): a Pro venue's offer with the disclaimer and the link to its inquiry form; no
 * Free venue's; empty, it says so, links to the listing and is noindex. The card badge on the listing, and the links in
 * the footer and the phone menu.
 */
final class LastMinutePageTest extends WebTestCase
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

    public function test_the_page_lists_a_pro_offer_and_no_free_one(): void
    {
        $pro = DetailVenues::minimal('seehaus', 'Seehaus Pro', KnownTiers::PRO);
        $free = DetailVenues::minimal('gartenhaus', 'Gartenhaus Free', KnownTiers::FREE);
        $this->offer($pro, ['priceText' => 'Seminarpauschale ab CHF 89', 'minGuests' => 20]);
        $this->offer($free, ['priceText' => 'Nur mit Pro']);

        $crawler = $this->client->request('GET', '/kurzfristig-frei');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Kurzfristig frei');
        self::assertCount(0, $crawler->filter('meta[name="robots"]'));
        self::assertSame('http://localhost/kurzfristig-frei', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertStringContainsString('Ohne Gewähr: Ob das Datum noch frei ist, bestätigt dir die Location erst auf deine Anfrage.', $crawler->filter('main')->text());
        $entries = $crawler->filter('.last-minute__entry');
        self::assertCount(1, $entries);
        self::assertStringContainsString('Seehaus Pro', $entries->text());
        self::assertStringContainsString('Seminarpauschale ab CHF 89', $entries->text());
        self::assertStringContainsString('ab 20 Personen', $entries->text());
        self::assertStringContainsString('Ganze Location', $entries->text());
        self::assertSame('/eventlokal/seehaus', $entries->filter('a.btn')->last()->attr('href'), 'no inquiry address: its page, which has no #anfrage (F16)');
        self::assertStringNotContainsString('Nur mit Pro', $crawler->filter('main')->text());
    }

    public function test_the_link_opens_the_form_of_a_venue_with_an_inquiry_address(): void
    {
        $pro = DetailVenues::minimal('seehaus', 'Seehaus Pro', KnownTiers::PRO);
        $this->connection->update('venues', ['inquiry_email' => 'info@seehaus.example'], ['id' => $pro->id()]);
        $this->offer($pro);

        $crawler = $this->client->request('GET', '/kurzfristig-frei');

        self::assertSame('/eventlokal/seehaus#anfrage', $crawler->filter('.last-minute__entry a.btn')->last()->attr('href'));
    }

    public function test_empty_it_says_so_links_to_the_listing_and_is_noindex(): void
    {
        $crawler = $this->client->request('GET', '/kurzfristig-frei');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString('Gerade sind keine kurzfristigen Daten frei.', $crawler->filter('main')->text());
        self::assertSame('/eventlokale', $crawler->filter('.listing-empty a')->attr('href'));
    }

    public function test_the_card_of_a_pro_venue_with_an_offer_carries_the_badge(): void
    {
        $pro = DetailVenues::minimal('seehaus', 'Seehaus Pro', KnownTiers::PRO);
        DetailVenues::minimal('ohne', 'Ohne Angebot', KnownTiers::PRO);
        $free = DetailVenues::minimal('gartenhaus', 'Gartenhaus Free', KnownTiers::FREE);
        $this->offer($pro);
        $this->offer($free);

        $crawler = $this->client->request('GET', '/eventlokale');

        self::assertResponseIsSuccessful();
        $badged = $crawler->filter('.card__title')->reduce(static fn ($title): bool => str_contains($title->text(), 'Kurzfristig frei'));
        self::assertCount(1, $badged);
        self::assertStringContainsString('Seehaus Pro', $badged->text());
    }

    public function test_the_footer_and_the_menu_link_the_page(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame('Kurzfristig frei', $crawler->filter('.site-footer a[href="/kurzfristig-frei"]')->text());
        self::assertCount(2, $crawler->filter('a[href="/kurzfristig-frei"]'), 'the footer and the phone menu');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function offer(Venue $venue, array $attributes = []): void
    {
        $day = new \DateTimeImmutable('today +5 days', new \DateTimeZone('Europe/Zurich'));
        LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => $day, 'endsOn' => $day] + $attributes);
    }
}
