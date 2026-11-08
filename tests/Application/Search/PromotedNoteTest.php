<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Geo\Entity\Town;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

use function Zenstruck\Foundry\Persistence\repository;

/**
 * D40, G13: every surface that shows the «Top-Eintrag» badge explains it, with the badge's tooltip and one footnote
 * linking to the explanation on /location-eintragen; a surface without a promoted entry has no footnote.
 */
final class PromotedNoteTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string NOTE_LINK = '.promoted-note a[href="/location-eintragen#top-eintrag"]';
    private const string TITLE = 'Bezahlter Eintrag, steht in den Suchergebnissen weiter oben';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    #[DataProvider('surfaces')]
    public function test_a_surface_with_a_promoted_entry_explains_the_badge(string $surface): void
    {
        $paid = $this->venue('bezahlte-location', 'Bezahlte Location', KnownTiers::PRO);
        $free = $this->venue('gratis-location', 'Gratis Location', KnownTiers::FREE);

        $crawler = $this->surface($surface, $paid, $free);

        $badge = $crawler->filter($this->badgeScope($surface).' .badge');
        self::assertGreaterThan(0, $badge->count(), $surface);
        self::assertSame('Top-Eintrag', $badge->first()->text(), $surface);
        self::assertSame(self::TITLE, $badge->first()->attr('title'), $surface);
        self::assertCount(1, $crawler->filter(self::NOTE_LINK), $surface);
        self::assertSame('Top-Eintrag: bezahlter Eintrag, der in den Suchergebnissen weiter oben steht. Mehr dazu zum Top-Eintrag', $crawler->filter('.promoted-note')->text());
        self::assertSame('zum Top-Eintrag', $crawler->filter(self::NOTE_LINK.' .visually-hidden')->text(), $surface);
    }

    #[DataProvider('surfaces')]
    public function test_a_surface_without_a_promoted_entry_has_no_footnote(string $surface): void
    {
        $first = $this->venue('erste-location', 'Erste Location', KnownTiers::FREE);
        $second = $this->venue('zweite-location', 'Zweite Location', KnownTiers::FREE);

        $crawler = $this->surface($surface, $first, $second);

        self::assertCount(0, $crawler->filter('.promoted-note'), $surface);
        self::assertCount(0, $crawler->filter('main .badge'), $surface);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function surfaces(): iterable
    {
        yield 'listing' => ['listing'];
        yield 'home spotlight' => ['home'];
        yield 'near me' => ['nearby'];
        yield 'merkliste' => ['merkliste'];
        yield 'detail page' => ['detail'];
        yield 'similar venues' => ['similar'];
    }

    public function test_the_home_spotlight_shows_the_badge(): void
    {
        $this->venue('bezahlte-location', 'Bezahlte Location', KnownTiers::PRO);

        $crawler = $this->get('/');

        self::assertCount(1, $crawler->filter('.home-spotlight .card .badge'));
        self::assertCount(1, $crawler->filter('.home-spotlight '.self::NOTE_LINK));
    }

    /**
     * The first venue is the promoted one (when there is one), the second a Free one; the page opened is the one
     * whose badge counts (the detail page of the first, the similar band of the second's detail page).
     */
    private function surface(string $surface, Venue $first, Venue $second): Crawler
    {
        return match ($surface) {
            'listing' => $this->get('/eventlokale'),
            'home' => $this->get('/'),
            'nearby' => $this->get('/eventlokal-in-meiner-naehe?ort=8712'),
            'merkliste' => $this->merkliste($first, $second),
            'detail' => $this->get('/eventlokal/'.$this->slug($first)),
            'similar' => $this->get('/eventlokal/'.$this->slug($second)),
            default => self::fail($surface),
        };
    }

    private function badgeScope(string $surface): string
    {
        return match ($surface) {
            'detail' => '.venue-head',
            'similar' => '.similar-venues',
            default => 'main',
        };
    }

    private function merkliste(Venue $first, Venue $second): Crawler
    {
        $this->client->getCookieJar()->set(new Cookie('merkliste', $first->number().','.$second->number(), null, '/', 'localhost'));

        return $this->get('/merkliste');
    }

    private function get(string $path): Crawler
    {
        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful($path);

        return $crawler;
    }

    private function venue(string $slug, string $name, string $tier): Venue
    {
        $town = repository(Town::class)->findOneBy(['name' => 'Stäfa']);
        self::assertInstanceOf(Town::class, $town);

        $venue = VenueFactory::createOne(['name' => $name, 'tier' => $tier, 'town' => $town, 'lat' => $town->lat(), 'lng' => $town->lng(), 'capacityMin' => 20, 'capacityMax' => 80]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);

        return $venue;
    }

    private function slug(Venue $venue): string
    {
        $slug = $this->connection->fetchOne('SELECT slug FROM venue_translations WHERE venue_id = ?', [$venue->id()]);
        self::assertIsString($slug);

        return $slug;
    }
}
