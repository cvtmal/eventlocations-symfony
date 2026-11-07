<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Geo\ValueObject\Canton;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Search\LandingFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * How the landing pages (#17) link: "Andere suchten auch nach" per page type (LP-5, P4), the
 * region map's canton paths, and the overlay forms' actions and hidden state (P1, P2). Live
 * pages only, never a query URL. The client shares the test's connection (no reboot).
 */
final class LandingLinksTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;

    /** Ten live cantons: ZH 15 (Meilen 6, Stäfa 5, Zürich 4), BE 6, eight more at 5. */
    private const CANTONS_AT_FIVE = [Canton::AG, Canton::GR, Canton::LU, Canton::SG, Canton::SO, Canton::SZ, Canton::TG, Canton::VS];

    /** The top nine after Zürich and Bern: count, then code; VS is the tenth. */
    private const TOP_AFTER_ZH_BE = [
        '/eventlokale/aargau', '/eventlokale/graubuenden', '/eventlokale/luzern',
        '/eventlokale/st-gallen', '/eventlokale/solothurn', '/eventlokale/schwyz',
        '/eventlokale/thurgau',
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

        $this->inTown('Meilen', 6);
        $this->inTown('Stäfa', 5);
        $this->inTown('Zürich', 4); // under the threshold: no town page
        $this->withBuilding($this->inCanton(Canton::BE, 6), 'hotel');
        foreach (self::CANTONS_AT_FIVE as $canton) {
            $venues = $this->inCanton($canton, 5);
            if (Canton::AG === $canton) {
                $this->withBuilding($venues, 'castle');
            }
        }
        $this->withBuilding($this->inCanton(Canton::GE, 4), 'cafe'); // thin canton, thin building
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_listing_links_the_top_nine_cantons_then_the_buildings(): void
    {
        $links = $this->related($this->get('/eventlokale'));

        self::assertSame([
            '/eventlokale/zuerich', '/eventlokale/bern', ...self::TOP_AFTER_ZH_BE,
            '/eventlokale/hotel', '/eventlokale/schloss',
        ], array_column($links, 'href'));
        self::assertSame(
            ['Hochzeit Zürich', 'Hochzeit Bern', 'Hochzeit Aargau', 'Hochzeit Graubünden', 'Hochzeit Luzern', 'Hochzeit St. Gallen', 'Hochzeit Solothurn', 'Hochzeit Schwyz', 'Hochzeit Thurgau', 'Hochzeit im Hotel', 'Hochzeit im Schloss'],
            array_column($links, 'label'),
        );
    }

    public function test_a_canton_page_links_its_towns_first_and_never_itself(): void
    {
        $links = $this->related($this->get('/eventlokale/zuerich'));

        self::assertSame([
            '/eventlokale/zuerich/meilen', '/eventlokale/zuerich/staefa',
            '/eventlokale/bern', ...self::TOP_AFTER_ZH_BE, '/eventlokale/wallis',
            '/eventlokale/hotel', '/eventlokale/schloss',
        ], array_column($links, 'href'));
        self::assertSame(['Hochzeit Meilen', 'Hochzeit Stäfa'], \array_slice(array_column($links, 'label'), 0, 2));
    }

    public function test_a_town_page_links_its_siblings_and_its_canton(): void
    {
        $links = $this->related($this->get('/eventlokale/zuerich/meilen'));

        self::assertSame([
            '/eventlokale/zuerich/staefa', '/eventlokale/zuerich',
            '/eventlokale/bern', ...self::TOP_AFTER_ZH_BE, '/eventlokale/wallis',
            '/eventlokale/hotel', '/eventlokale/schloss',
        ], array_column($links, 'href'));
        self::assertSame(['Hochzeit Stäfa', 'Hochzeit Zürich'], \array_slice(array_column($links, 'label'), 0, 2));
    }

    public function test_a_building_page_links_the_cantons_and_the_other_buildings(): void
    {
        $links = $this->related($this->get('/eventlokale/hotel'));

        self::assertSame([
            '/eventlokale/zuerich', '/eventlokale/bern', ...self::TOP_AFTER_ZH_BE,
            '/eventlokale/schloss',
        ], array_column($links, 'href'));
    }

    public function test_the_region_map_knows_exactly_the_live_canton_paths(): void
    {
        $crawler = $this->get('/eventlokale/schloss');

        $form = $crawler->filter('form#filter-region-form');
        $paths = json_decode((string) $form->attr('data-landing'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($paths);
        ksort($paths);
        self::assertSame([
            'ag' => '/eventlokale/aargau', 'be' => '/eventlokale/bern', 'gr' => '/eventlokale/graubuenden',
            'lu' => '/eventlokale/luzern', 'sg' => '/eventlokale/st-gallen', 'so' => '/eventlokale/solothurn',
            'sz' => '/eventlokale/schwyz', 'tg' => '/eventlokale/thurgau', 'vs' => '/eventlokale/wallis',
            'zh' => '/eventlokale/zuerich',
        ], $paths);

        // P1: the region form keeps the plain listing as its action and carries the building page's facet.
        self::assertSame('/eventlokale', $form->attr('action'));
        self::assertSame(['building[]' => ['castle']], $this->hiddenState($crawler, '#filter-region-form'));
    }

    public function test_the_more_form_on_a_canton_page_submits_to_its_path_without_the_canton(): void
    {
        $crawler = $this->get('/eventlokale/zuerich?guests=100&rooms=2');

        $form = $crawler->filter('form#filter-more-form');
        self::assertSame('/eventlokale/zuerich', $form->attr('action'));
        self::assertSame(['guests' => ['100']], $this->hiddenState($crawler, '#filter-more-form'));
        self::assertSame('/_search/count?canton=zh&', $form->attr('data-live-count-url'));
    }

    public function test_the_more_form_on_a_building_page_owns_the_building_and_submits_to_the_listing(): void
    {
        $crawler = $this->get('/eventlokale/hotel');

        $form = $crawler->filter('form#filter-more-form');
        self::assertSame('/eventlokale', $form->attr('action'));
        self::assertSame([], $this->hiddenState($crawler, '#filter-more-form'));
        self::assertCount(1, $form->filter('input[name="building[]"][value="hotel"][checked]'));
    }

    public function test_the_canton_chip_on_a_canton_page_removes_the_path(): void
    {
        $crawler = $this->get('/eventlokale/zuerich?guests=100');

        $chip = $crawler->filter('.filter-bar a.chip')->reduce(static fn (Crawler $chip): bool => 'Zürich' === $chip->text());
        self::assertCount(1, $chip);
        self::assertSame('/eventlokale?guests=100', $chip->attr('href'));
    }

    private function get(string $uri): Crawler
    {
        $crawler = $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful($uri);

        return $crawler;
    }

    /**
     * "Andere suchten auch nach", checked for P4's invariants: never a query URL, never the page itself.
     *
     * @return list<array{label: string, href: string}>
     */
    private function related(Crawler $crawler): array
    {
        $current = (string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH);
        $links = $crawler->filter('.related__list a')->each(static fn (Crawler $a): array => ['label' => $a->text(), 'href' => (string) $a->attr('href')]);
        foreach ($links as $link) {
            self::assertStringNotContainsString('?', $link['href']);
            self::assertNotSame($current, $link['href']);
        }

        return $links;
    }

    /**
     * @return array<string, list<string>>
     */
    private function hiddenState(Crawler $crawler, string $form): array
    {
        $state = [];
        foreach ($crawler->filter($form.' > input[type="hidden"]:not([data-region-value])') as $input) {
            \assert($input instanceof \DOMElement);
            $state[$input->getAttribute('name')][] = $input->getAttribute('value');
        }

        return $state;
    }
}
