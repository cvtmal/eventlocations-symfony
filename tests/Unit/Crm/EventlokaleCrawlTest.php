<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\EventlokaleCache;
use App\Crm\EventlokaleClient;
use App\Crm\EventlokaleCrawl;
use App\Crm\EventlokaleCrawlResult;
use App\Tests\Support\Crm\EventlokalePayloads;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class EventlokaleCrawlTest extends TestCase
{
    private const string BASE = 'https://www.eventlokale.ch';
    private const string SEARCH = 'POST /api/guest/location/search/draw';
    private const string TERMS = 'GET /api/guest/location/all-taxonomies';

    private string $root;
    private MockClock $clock;
    /** @var array<string, list<\Closure():MockResponse>> answers per "METHOD /path", taken in turn; the last one repeats */
    private array $routes = [];
    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/eventlokale-'.bin2hex(random_bytes(4));
        $this->clock = new MockClock('2026-11-03 10:00:00');
        // Page 1 holds 1 and 2, page 2 holds 2 again and 3.
        $this->route(self::TERMS, static fn (): MockResponse => new JsonMockResponse(EventlokalePayloads::taxonomies()));
        $this->route(self::SEARCH, ...[
            fn (): MockResponse => $this->page([1, 2], 1),
            fn (): MockResponse => $this->page([2, 3], 2),
            fn (): MockResponse => $this->page([1, 2], 1),
            fn (): MockResponse => $this->page([2, 3], 2),
        ]);
        foreach ([1, 2, 3] as $id) {
            $this->route($this->detail($id), static fn (): MockResponse => new JsonMockResponse(EventlokalePayloads::detail($id)));
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root.'/eventlokale/venues/*') ?: []);
        array_map('unlink', glob($this->root.'/eventlokale/*.json') ?: []);
        @rmdir($this->root.'/eventlokale/venues');
        @rmdir($this->root.'/eventlokale');
        @rmdir($this->root);
    }

    public function test_it_pages_the_list_dedupes_by_id_and_caches_the_terms(): void
    {
        $result = $this->crawl(listOnly: true);

        $index = $this->cache()->readIndex();
        self::assertSame([1, 2, 3], array_column($index['items'], 'id'));
        self::assertSame(3, $result->listed);
        self::assertSame(935, $result->total);
        self::assertSame(EventlokalePayloads::taxonomies(), $this->cache()->readTaxonomies());
    }

    public function test_it_caches_every_detail_as_served(): void
    {
        $result = $this->crawl();

        self::assertSame(3, $result->fetched);
        foreach ([1, 2, 3] as $id) {
            self::assertSame(EventlokalePayloads::detail($id), $this->cache()->readDetail($id));
        }
        self::assertFalse($result->rateLimited);
    }

    public function test_a_second_run_requests_nothing_and_counts_the_cached_details(): void
    {
        $this->crawl();
        $this->requested = [];

        $result = $this->crawl();

        self::assertSame([], $this->requested);
        self::assertSame(3, $result->skipped);
        self::assertSame(0, $result->fetched);
    }

    public function test_refresh_fetches_everything_again(): void
    {
        $this->crawl();
        $this->requested = [];

        $result = $this->crawl(refresh: true);

        self::assertCount(1 + 2 + 3, $this->requested);
        self::assertSame(3, $result->fetched);
        self::assertSame(0, $result->skipped);
    }

    public function test_limit_caps_the_details_fetched_in_one_run(): void
    {
        $result = $this->crawl(limit: 1);

        self::assertSame(1, $result->fetched);
        self::assertSame([self::TERMS, self::SEARCH, self::SEARCH, $this->detail(1)], $this->requested);
    }

    public function test_list_only_fetches_no_detail(): void
    {
        $result = $this->crawl(listOnly: true);

        self::assertSame([self::TERMS, self::SEARCH, self::SEARCH], $this->requested);
        self::assertSame(0, $result->fetched);
    }

    public function test_it_pauses_before_every_request_but_the_first(): void
    {
        $this->crawl(delayMs: 1500, jitterMs: 0);

        self::assertCount(6, $this->requested);
        self::assertSame('2026-11-03 10:00:07.500000', $this->clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function test_a_gone_venue_is_marked_and_skipped_on_the_next_run(): void
    {
        $this->route($this->detail(2), static fn (): MockResponse => new MockResponse('', ['http_code' => 404]));

        $result = $this->crawl();

        self::assertSame(1, $result->gone);
        self::assertSame(2, $result->fetched);
        self::assertTrue($this->cache()->isGone(2));
        $this->requested = [];
        self::assertSame(3, $this->crawl()->skipped);
        self::assertSame([], $this->requested);
    }

    public function test_a_failed_detail_is_counted_and_the_crawl_goes_on(): void
    {
        $this->route($this->detail(2), static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        $result = $this->crawl();

        self::assertSame(1, $result->failed);
        self::assertSame(2, $result->fetched);
        self::assertFalse($this->cache()->isSettled(2));
        self::assertTrue($this->cache()->isSettled(3));
        self::assertFalse($result->rateLimited);
    }

    public function test_three_rate_limit_answers_in_a_row_stop_the_crawl(): void
    {
        $this->addDetails(4, 5);
        foreach ([2, 3, 4] as $id) {
            $this->route($this->detail($id), $this->tooManyRequests(...));
        }

        $result = $this->crawl();

        self::assertTrue($result->rateLimited);
        self::assertSame(1, $result->fetched);
        self::assertSame(0, $result->failed);
        self::assertNotContains($this->detail(5), $this->requested);
        self::assertTrue($this->cache()->isSettled(1));
        self::assertFalse($this->cache()->isSettled(2));
    }

    public function test_an_answer_resets_the_rate_limit_streak(): void
    {
        $this->addDetails(4, 5);
        foreach ([1, 3, 4] as $id) {
            $this->route($this->detail($id), $this->tooManyRequests(...));
        }

        $result = $this->crawl();

        self::assertFalse($result->rateLimited);
        self::assertSame(2, $result->fetched);
        self::assertContains($this->detail(5), $this->requested);
    }

    public function test_a_rate_limited_list_page_is_repeated_and_counts_toward_the_streak(): void
    {
        $this->route(self::SEARCH, $this->tooManyRequests(...), $this->tooManyRequests(...), fn (): MockResponse => $this->page([1, 2], 1), fn (): MockResponse => $this->page([2, 3], 2));

        self::assertSame(3, $this->crawl(listOnly: true)->listed);

        $this->tearDown();
        $this->route(self::SEARCH, $this->tooManyRequests(...));
        $this->requested = [];

        $result = $this->crawl();

        self::assertTrue($result->rateLimited);
        self::assertSame([self::TERMS, self::SEARCH, self::SEARCH, self::SEARCH], $this->requested);
    }

    public function test_a_failed_list_page_ends_the_run(): void
    {
        $this->route(self::SEARCH, static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));

        $result = $this->crawl();

        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->listed);
        self::assertFalse($result->rateLimited);
        self::assertSame([self::TERMS, self::SEARCH], $this->requested);
    }

    private function crawl(int $delayMs = 0, int $jitterMs = 0, ?int $limit = null, bool $refresh = false, bool $listOnly = false): EventlokaleCrawlResult
    {
        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $key = $method.' '.substr($url, \strlen(self::BASE));
            $this->requested[] = $key;
            $queue = $this->routes[$key] ?? throw new \LogicException('No route for '.$key);
            if (\count($queue) > 1) {
                array_shift($this->routes[$key]);
            }

            return $queue[0]();
        }, self::BASE);

        return (new EventlokaleCrawl(new EventlokaleClient($http), $this->clock))
            ->run($this->root, $delayMs, $jitterMs, $limit, $refresh, $listOnly);
    }

    private function route(string $key, \Closure ...$answers): void
    {
        $this->routes[$key] = array_values($answers);
    }

    /**
     * Adds ids to both list pages, after the default ones.
     */
    private function addDetails(int ...$ids): void
    {
        $this->route(self::SEARCH, fn (): MockResponse => $this->page([1, 2], 1), fn (): MockResponse => $this->page([2, 3, ...array_values($ids)], 2));
        foreach ($ids as $id) {
            $this->route($this->detail($id), static fn (): MockResponse => new JsonMockResponse(EventlokalePayloads::detail($id)));
        }
    }

    /**
     * @param list<int> $ids
     */
    private function page(array $ids, int $page): MockResponse
    {
        return new JsonMockResponse(EventlokalePayloads::searchPage(
            array_map(static fn (int $id): array => EventlokalePayloads::listItem($id), $ids),
            $page,
            2,
            935,
        ));
    }

    private function tooManyRequests(): MockResponse
    {
        return new MockResponse('', ['http_code' => 429]);
    }

    private function detail(int $id): string
    {
        return 'GET /api/guest/location/'.$id.'/get';
    }

    private function cache(): EventlokaleCache
    {
        return new EventlokaleCache($this->root);
    }
}
