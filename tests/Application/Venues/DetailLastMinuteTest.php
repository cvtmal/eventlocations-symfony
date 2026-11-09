<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\LastMinuteOfferFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The detail section «Kurzfristig frei» (G14, D55): a Pro venue's live offers with the disclaimer and the link to the
 * inquiry form; nothing for a Free venue's offer or a past one.
 */
final class DetailLastMinuteTest extends WebTestCase
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

    public function test_a_pro_venue_shows_its_offers_with_the_disclaimer(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        $this->connection->update('venues', ['inquiry_email' => 'info@gartenhaus.example'], ['id' => $venue->id()]);
        $day = new \DateTimeImmutable('today +5 days', new \DateTimeZone('Europe/Zurich'));
        LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => $day, 'endsOn' => $day->modify('+1 day'), 'priceText' => 'Apéro-Paket CHF 1500', 'terms' => 'Nur abends.']);
        LastMinuteOfferFactory::new()->expired()->create(['venue' => $venue, 'priceText' => 'Vorbei']);

        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('section#kurzfristig-frei');
        self::assertCount(1, $section);
        self::assertSame('Kurzfristig frei', $section->filter('h2')->text());
        self::assertCount(1, $section->filter('.last-minute-offer'));
        self::assertStringContainsString('Apéro-Paket CHF 1500', $section->text());
        self::assertStringContainsString('Nur abends.', $section->text());
        self::assertStringContainsString('bestätigt dir die Location erst auf deine Anfrage', $section->text());
        self::assertSame('#anfrage', $section->filter('a.btn')->attr('href'));
        self::assertStringNotContainsString('Vorbei', $section->text());
    }

    public function test_without_an_inquiry_address_the_link_goes_to_kontakt_or_is_left_out(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);
        LastMinuteOfferFactory::createOne(['venue' => $venue]);

        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertCount(1, $crawler->filter('section#kurzfristig-frei'));
        self::assertCount(0, $crawler->filter('section#kurzfristig-frei a.btn'), 'no form, no Kontakt: no link (F16)');

        $this->connection->update('venues', ['phone' => '+41441234567'], ['id' => $venue->id()]);
        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertSame('#kontakt', $crawler->filter('section#kurzfristig-frei a.btn')->attr('href'));
        self::assertCount(0, $crawler->filter('a[href$="#anfrage"]'));
    }

    public function test_a_free_venue_shows_no_section(): void
    {
        $venue = DetailVenues::minimal(tier: KnownTiers::FREE);
        LastMinuteOfferFactory::createOne(['venue' => $venue]);

        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#kurzfristig-frei'));
    }
}
