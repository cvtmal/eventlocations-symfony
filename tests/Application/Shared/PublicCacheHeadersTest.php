<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Tests\Support\Http\PublicRouteFixtures;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The header table of S10 (#19, research D3): one row per route and answer, with the exact
 * `Cache-Control`. A new public route needs a row here and parameters in the walker.
 */
final class PublicCacheHeadersTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use PublicRouteFixtures;

    /** Two hours to the next Zurich midnight from FROZEN_NOW. */
    private const LISTING = 'public, s-maxage=7200, stale-if-error=86400, stale-while-revalidate=86400';
    private const PAGE = 'public, s-maxage=86400, stale-if-error=86400, stale-while-revalidate=86400';
    private const MISSING = 'public, s-maxage=3600';
    private const COUNT = 'max-age=60, public, s-maxage=86400, stale-while-revalidate=86400';
    private const PLACES = 'max-age=3600, public, s-maxage=86400';
    private const PRIVATE = 'no-store, private';
    private const NEVER_STORED = 'no-cache, private';

    private const DETAIL = '/hochzeitslocation/'.DetailVenues::FULL_SLUG;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime(self::FROZEN_NOW);
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
     * The URLs may hold {number}, {gone}, {town} and {building}. The route is null outside the public table.
     *
     * @return iterable<string, array{?string, string, string, int, string, ?string}> route, method, URL, status, Cache-Control, Vary
     */
    public static function headers(): iterable
    {
        yield 'listing' => ['listing', 'GET', '/hochzeitslocations', 200, self::LISTING, null];
        yield 'listing past the last page' => ['listing', 'GET', '/hochzeitslocations?page=2', 404, self::MISSING, null];
        yield 'canton page' => ['listing_landing', 'GET', '/hochzeitslocations/zuerich', 200, self::LISTING, null];
        yield 'building page' => ['listing_landing', 'GET', '/hochzeitslocations/{building}', 200, self::LISTING, null];
        yield 'unknown landing page' => ['listing_landing', 'GET', '/hochzeitslocations/unbekannt', 404, self::MISSING, null];
        yield 'town page' => ['listing_town', 'GET', '/hochzeitslocations/zuerich/{town}', 200, self::LISTING, null];
        yield 'unknown town page' => ['listing_town', 'GET', '/hochzeitslocations/zuerich/unbekannt', 404, self::MISSING, null];
        yield 'detail' => ['venue_show', 'GET', self::DETAIL, 200, self::PAGE, null];
        yield 'detail, history slug' => ['venue_show', 'GET', '/hochzeitslocation/'.self::HISTORY_SLUG, 301, self::PAGE, null];
        yield 'detail, unknown' => ['venue_show', 'GET', '/hochzeitslocation/unbekannt', 404, self::MISSING, null];
        yield 'detail, unpublished' => ['venue_show', 'GET', '/hochzeitslocation/{gone}', 410, self::MISSING, null];
        yield 'impressum' => ['impressum', 'GET', '/impressum', 200, self::PAGE, null];
        yield 'datenschutz' => ['datenschutz', 'GET', '/datenschutz', 200, self::PAGE, null];
        yield 'sitemap index' => ['sitemap', 'GET', '/sitemap.xml', 200, self::PAGE, null];
        yield 'pages sitemap' => ['sitemap_pages', 'GET', '/sitemap-pages.xml', 200, self::PAGE, null];
        yield 'venue sitemap' => ['sitemap_venues', 'GET', '/sitemap-venues-1.xml', 200, self::PAGE, null];
        yield 'venue sitemap past the last file' => ['sitemap_venues', 'GET', '/sitemap-venues-99.xml', 404, self::PAGE, null];
        yield 'robots' => ['robots', 'GET', '/robots.txt', 200, self::PAGE, null];
        yield 'llms.txt' => ['llms_txt', 'GET', '/llms.txt', 200, self::PAGE, null];
        yield 'venue markdown' => ['venue_markdown', 'GET', self::DETAIL.'.md', 200, self::PAGE, null];
        yield 'venue markdown, history slug' => ['venue_markdown', 'GET', '/hochzeitslocation/'.self::HISTORY_SLUG.'.md', 301, self::PAGE, null];
        yield 'venue markdown, unknown' => ['venue_markdown', 'GET', '/hochzeitslocation/unbekannt.md', 404, self::MISSING, null];
        yield 'venue markdown, unpublished' => ['venue_markdown', 'GET', '/hochzeitslocation/{gone}.md', 410, self::MISSING, null];
        yield 'count' => ['_search_count', 'GET', '/_search/count', 200, self::COUNT, null];
        yield 'places' => ['_search_places', 'GET', '/_search/places?q=Zue', 200, self::PLACES, null];
        yield 'phone' => ['venue_phone', 'GET', self::DETAIL.'/telefon', 200, self::PRIVATE, 'Accept'];
        yield 'phone, history slug' => ['venue_phone', 'GET', '/hochzeitslocation/'.self::HISTORY_SLUG.'/telefon', 301, self::PRIVATE, 'Accept'];
        yield 'merkliste' => ['merkliste', 'GET', '/merkliste', 200, self::PRIVATE, null];
        yield 'merkliste toggle' => ['merkliste_toggle', 'POST', '/merkliste/{number}', 303, self::PRIVATE, null];
        yield 'merkliste sync' => ['merkliste_sync', 'POST', '/merkliste', 204, self::PRIVATE, null];
        yield 'inquiry' => ['inquiry_submit', 'POST', self::DETAIL.'/anfrage', 303, self::PRIVATE, null];
        yield 'home' => ['home', 'GET', '/', 200, self::LISTING, null];
        yield 'home, HEAD' => ['home', 'HEAD', '/', 200, self::LISTING, null];
        yield 'health' => ['health', 'GET', '/health', 200, self::PRIVATE, null];
        yield 'stats' => ['_stats_collect', 'POST', '/_stats', 204, self::PRIVATE, null];
        yield 'no route' => [null, 'GET', '/gibt-es-nicht', 404, self::NEVER_STORED, null];
        yield 'back office' => [null, 'GET', '/admin/login', 200, self::PRIVATE, null];
    }

    #[DataProvider('headers')]
    public function test_a_route_sends_its_cache_headers(?string $route, string $method, string $url, int $status, string $cacheControl, ?string $vary): void
    {
        $data = $this->seedPublicRoutes();
        $posted = ['_token' => 'csrf-token'] + InquiryFormFields::valid(['started' => $data['started']]);

        $this->client->request(
            $method,
            strtr($url, ['{number}' => (string) $data['number'], '{gone}' => $data['gone'], '{town}' => $data['town'], '{building}' => $data['building']]),
            'POST' === $method ? $posted : [],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $response = $this->client->getResponse();

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($cacheControl, $response->headers->get('Cache-Control'));
        self::assertSame($vary, $response->headers->get('Vary'));
    }

    public function test_the_phone_limit_answers_private(): void
    {
        $this->seedPublicRoutes();
        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('GET', self::DETAIL.'/telefon');
            self::assertResponseIsSuccessful();
        }

        $this->client->request('GET', self::DETAIL.'/telefon');

        self::assertResponseStatusCodeSame(429);
        self::assertSame(self::PRIVATE, $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('Accept', $this->client->getResponse()->headers->get('Vary'));
    }

    public function test_the_table_has_a_row_for_every_public_route(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $routes = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $hasFirewall = 1 === preg_match('#^/(admin|konto)(/|$)#', $route->getPath());
            $routes[] = $hasFirewall ? null : $route->getDefault('_canonical_route') ?? $name;
        }
        $routes = array_diff(array_filter($routes), PublicPagesStartNoSessionTest::DEV_ONLY);
        $rows = array_filter(array_column(iterator_to_array(self::headers()), 0));

        self::assertEqualsCanonicalizing(array_values(array_unique($routes)), array_values(array_unique($rows)));
    }
}
