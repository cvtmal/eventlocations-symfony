<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\SiteImport;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\FetchLimits;
use App\Shared\Http\RefusalReason;
use App\Shared\Http\SafeFetcher;
use App\Tests\Support\Http\StubHostResolver;
use App\Venues\Exception\UnreadablePage;
use App\Venues\SiteImport\SiteImporter;
use App\Venues\SiteImport\SitePageParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The caps the importer adds on top of SafeFetcher's: eight pages and sixty image references per site, and a crawl
 * that goes on when a later page is refused.
 */
final class SiteImporterTest extends TestCase
{
    private const string HOST = 'https://www.beispiel-venue.example';

    /** @var list<string> */
    private array $requested = [];

    /**
     * @param array<string, string> $pages path => body, robots.txt included when it exists
     */
    private function importer(array $pages, ?FetchLimits $limits = null): SiteImporter
    {
        $clock = new MockClock('2026-11-07 10:00:00');
        $client = new MockHttpClient(function (string $method, string $url) use ($pages): MockResponse {
            $this->requested[] = $url;
            $body = $pages[substr($url, \strlen(self::HOST))] ?? null;

            return null === $body
                ? new MockResponse('not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']])
                : new MockResponse($body, ['response_headers' => ['content-type' => str_ends_with($url, '/robots.txt') ? 'text/plain' : 'text/html; charset=utf-8']]);
        });
        $fetcher = new SafeFetcher($client, (new StubHostResolver())->byDefault(StubHostResolver::PUBLIC_V4), $clock, 'test-agent', $limits ?? new FetchLimits());

        return new SiteImporter($fetcher, new SitePageParser(), $clock);
    }

    private static function page(string $links, int $images, string $prefix): string
    {
        $pictures = implode('', array_map(static fn (int $i): string => \sprintf('<img src="/medien/%s-%d.jpg" width="1200" height="800" alt="Bild %d">', $prefix, $i, $i), range(1, $images)));

        return '<html><head><title>Beispiel</title></head><body>'.$links.$pictures.'</body></html>';
    }

    public function test_at_most_sixty_image_references_are_kept_in_the_order_they_were_found(): void
    {
        $result = $this->importer([
            '/' => self::page('<a href="/galerie">Galerie</a><a href="/bilder">Bilder</a>', 30, 'start'),
            '/galerie' => self::page('', 30, 'galerie'),
            '/bilder' => self::page('', 30, 'bilder'),
        ])->import(self::HOST.'/');

        self::assertCount(60, $result->candidates);
        self::assertSame(self::HOST.'/medien/start-1.jpg', $result->candidates[0]->imageUrl);
        // Equal scores rank the shorter path first: /bilder is read before /galerie and fills the list to 60.
        self::assertSame(self::HOST.'/medien/bilder-30.jpg', $result->candidates[59]->imageUrl);
        self::assertCount(3, $result->pages);
    }

    public function test_at_most_eight_pages_are_read(): void
    {
        $pages = ['/' => self::page(implode('', array_map(static fn (int $i): string => '<a href="/events/'.$i.'">Event '.$i.'</a>', range(1, 12))), 1, 'start')];
        foreach (range(1, 12) as $i) {
            $pages['/events/'.$i] = self::page('', 1, 'e'.$i);
        }

        $result = $this->importer($pages)->import(self::HOST.'/');

        self::assertCount(8, $result->pages);
        self::assertCount(8 + 1, $this->requested, 'eight pages and robots.txt');
        self::assertNotContains(self::HOST.'/events/12', $this->requested);
    }

    public function test_a_page_the_site_does_not_want_read_is_reported_and_the_crawl_goes_on(): void
    {
        $result = $this->importer([
            '/robots.txt' => "User-agent: *\nDisallow: /galerie\n",
            '/' => self::page('<a href="/galerie">Galerie</a><a href="/raeume">Räume</a><a href="/events">Events</a>', 1, 'start'),
            '/raeume' => self::page('', 2, 'raeume'),
            '/events' => '<html><body>nur Text</body></html>',
        ])->import(self::HOST.'/');

        self::assertSame([['url' => self::HOST.'/galerie', 'reason' => 'robots', 'detail' => '/galerie is disallowed by robots.txt']], $result->refused);
        self::assertSame([self::HOST.'/', self::HOST.'/raeume', self::HOST.'/events'], array_column($result->pages, 'url'));
        self::assertCount(3, $result->candidates);
        self::assertNotContains(self::HOST.'/galerie', $this->requested);
    }

    public function test_the_crawl_stops_when_the_byte_budget_is_used_up(): void
    {
        $result = $this->importer([
            '/' => self::page('<a href="/galerie">Galerie</a><a href="/raeume">Räume</a><a href="/events">Events</a>', 1, 'start'),
            '/galerie' => self::page('', 30, 'galerie'),
            '/raeume' => self::page('', 30, 'raeume'),
            '/events' => self::page('', 30, 'events'),
        ], new FetchLimits(maxTotalBytes: 6000))->import(self::HOST.'/');

        self::assertSame('byte_budget', $result->refused[0]['reason']);
        self::assertLessThan(4, \count($result->pages));
        self::assertLessThanOrEqual(6000, $result->bytes);
    }

    public function test_a_refused_entry_url_throws_and_a_result_records_what_happened(): void
    {
        try {
            $this->importer([])->import('http://169.254.169.254/');
            self::fail('Expected a refusal.');
        } catch (FetchRefused $e) {
            self::assertSame(RefusalReason::BlockedAddress, $e->reason);
        }
        self::assertSame([], $this->requested);

        $result = $this->importer(['/' => self::page('', 1, 'start')])->import(self::HOST.'/');
        $report = $result->report();
        self::assertSame(self::HOST.'/', $result->url);
        self::assertSame(self::HOST.'/', $report['final_url']);
        self::assertSame([['url' => self::HOST.'/', 'status' => 200, 'bytes' => \strlen(self::page('', 1, 'start'))]], $report['pages']);
        self::assertSame([], $report['refused']);
        self::assertGreaterThanOrEqual(1.0, $report['seconds'], 'the pause after robots.txt counts');
    }

    public function test_a_page_nested_too_deep_is_reported_and_the_crawl_goes_on(): void
    {
        $deep = '<html><body>'.str_repeat('<div>', 400).str_repeat('</div>', 400).'</body></html>';

        $result = $this->importer([
            '/' => self::page('<a href="/galerie">Galerie</a><a href="/raeume">Räume</a>', 1, 'start'),
            '/galerie' => $deep,
            '/raeume' => self::page('', 2, 'raeume'),
        ])->import(self::HOST.'/');

        self::assertSame([['url' => self::HOST.'/galerie', 'reason' => 'unreadable', 'detail' => 'the page nests elements more than 300 levels deep']], $result->refused);
        self::assertSame([self::HOST.'/', self::HOST.'/raeume'], array_column($result->pages, 'url'));
    }

    public function test_an_entry_page_nested_too_deep_stops_the_import(): void
    {
        $this->expectException(UnreadablePage::class);

        $this->importer(['/' => '<html><body>'.str_repeat('<div>', 400).'</body></html>'])->import(self::HOST.'/');
    }

    public function test_the_stored_url_is_the_normalised_one(): void
    {
        $result = $this->importer(['/' => self::page('', 1, 'start')])->import('HTTPS://WWW.Beispiel-Venue.example/#oben');

        self::assertSame(self::HOST.'/', $result->url);
    }
}
