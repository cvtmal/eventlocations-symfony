<?php

declare(strict_types=1);

namespace App\Tests\Application\Stats;

use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The markup stats.js reads (#47, C1): one `script[data-stats-page]` per public page with its page type and,
 * on the detail page, the venue number; a `data-stats-venue` per listing card; a `data-stats-click` kind on
 * each contact and map link of the detail page.
 */
final class BeaconMarkupTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

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

    public function test_each_page_names_its_type_and_only_the_detail_page_a_venue(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $venue = DetailVenues::full($this->em);

        $pages = [
            '/' => ['home', ''],
            '/eventlokale' => ['listing', ''],
            '/eventlokale/zuerich' => ['landing', ''],
            '/eventlokal/'.DetailVenues::FULL_SLUG => ['detail', (string) $venue->number()],
            '/merkliste' => ['merkliste', ''],
            '/datenschutz' => ['other', ''],
        ];
        foreach ($pages as $uri => [$page, $number]) {
            $tag = $this->get($uri)->filter('script[data-stats-page]');

            self::assertCount(1, $tag, $uri);
            self::assertSame($page, $tag->attr('data-stats-page'), $uri);
            self::assertSame($number, $tag->attr('data-stats-venue'), $uri);
            self::assertSame('/_stats', $tag->attr('data-stats-url'), $uri);
            self::assertSame('module', $tag->attr('type'), $uri);
        }
    }

    public function test_every_listing_card_carries_its_venue_number(): void
    {
        $numbers = array_map(static fn (Venue $venue): string => (string) $venue->number(), $this->inCanton(Canton::ZH, 5));
        sort($numbers);

        $cards = $this->get('/eventlokale')->filter('article.card');
        $carried = $cards->each(static fn (Crawler $card): string => (string) $card->attr('data-stats-venue'));
        sort($carried);

        self::assertCount(5, $cards);
        self::assertSame($numbers, $carried);
    }

    public function test_the_contact_and_map_links_carry_their_click_kind(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->get('/eventlokal/'.DetailVenues::FULL_SLUG);

        self::assertSame(
            ['phone', 'email', 'website', 'instagram'],
            $crawler->filter('#kontakt .venue-contact__link')->each(static fn (Crawler $link): string => (string) $link->attr('data-stats-click')),
        );
        self::assertSame('phone', $crawler->filter('a[data-phone-reveal]')->attr('data-stats-click'));
        self::assertSame('map', $crawler->filter('a.venue-map__link')->attr('data-stats-click'));
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }
}
