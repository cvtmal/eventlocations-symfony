<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Shared\Http\SafeFetcher;
use App\Shared\Translation\EnabledLocale;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Http\StubHostResolver;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Console\ImportSiteCommand;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\SiteImport\SiteImporter;
use App\Venues\SiteImport\SitePageParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:venues:import-site` (G3) end to end against a mocked network: the crawl lands in the private tables, a refused
 * entry URL stores nothing, and an unknown venue slug stops before any request.
 */
final class ImportSiteCommandTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;
    use RollsBackDatabase;

    private const string HOME = <<<'HTML'
        <html lang="de"><head><title>Schloss Musterburg</title>
        <meta property="og:image" content="/media/saal.jpg">
        <script type="application/ld+json">{"@type":"EventVenue","name":"Schloss Musterburg","telephone":"+41 44 555 01 23","address":{"@type":"PostalAddress","streetAddress":"Musterweg 12","postalCode":"8000","addressLocality":"Zürich"}}</script>
        </head><body><h1>Schloss Musterburg</h1><img src="/media/terrasse.jpg" width="1200" height="800" alt="Die Terrasse">
        <a href="/raeume">Unsere Räume</a><a href="/blog/neuigkeiten">Neuigkeiten</a></body></html>
        HTML;

    /** @var list<string> */
    private array $requested = [];

    public function test_the_crawl_is_stored_for_the_venue(): void
    {
        DetailVenues::minimal();
        $routes = [
            'https://www.beispiel-venue.example/' => self::HOME,
            'https://www.beispiel-venue.example/raeume' => '<html><body><h2>Der Saal</h2><p>Der Saal bietet Platz für bis zu 120 Personen.</p><img data-src="/media/saal-2.jpg" alt="Saal"></body></html>',
        ];

        [$status, $output] = $this->runCommand('https://www.beispiel-venue.example/', DetailVenues::MINIMAL_SLUG, $routes);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('Stored as source record', $output);
        self::assertSame(['https://www.beispiel-venue.example/robots.txt', 'https://www.beispiel-venue.example/', 'https://www.beispiel-venue.example/raeume'], $this->requested);

        $record = $this->connection->fetchAssociative('SELECT r.*, v.number FROM source_records r JOIN venues v ON v.id = r.venue_id');
        self::assertIsArray($record);
        $facts = json_decode((string) $record['facts'], true);
        self::assertSame('Schloss Musterburg', $facts['name'][0]['value']);
        self::assertSame('8000', $facts['address'][0]['value']['postal_code']);
        self::assertSame('+41 44 555 01 23', $facts['phone'][0]['value']);
        self::assertSame('Der Saal bietet Platz für bis zu 120 Personen.', $facts['snippet'][0]['value']);
        self::assertCount(2, json_decode((string) $record['report'], true)['pages']);

        $images = $this->connection->fetchFirstColumn('SELECT image_url FROM photo_candidates ORDER BY position');
        self::assertSame([
            'https://www.beispiel-venue.example/media/saal.jpg',
            'https://www.beispiel-venue.example/media/terrasse.jpg',
            'https://www.beispiel-venue.example/media/saal-2.jpg',
        ], $images);
    }

    public function test_what_the_page_says_cannot_drive_the_terminal(): void
    {
        // The parser decodes the entities, so the name holds a real "<href=...>" and an escape character.
        $page = '<html><head><meta property="og:site_name" content="Seeblick&lt;href=https://evil.example&gt;click&lt;/&gt;&#27;[2J"></head><body><h1>Seeblick</h1></body></html>';

        [$status, $output] = $this->runCommand('https://www.beispiel-venue.example/', null, ['https://www.beispiel-venue.example/' => $page]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertStringContainsString('<href=https://evil.example>click</>', $output, 'printed as text, not read as a link');
        self::assertSame(0, preg_match('/[\x00-\x08\x0b-\x1f\x7f]|\xc2[\x80-\x9f]/', $output), 'no control character reaches the terminal');
    }

    public function test_a_refused_entry_url_stores_nothing(): void
    {
        foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:8080/', 'file:///etc/passwd', 'https://user:pw@www.beispiel-venue.example/'] as $url) {
            [$status, $output] = $this->runCommand($url, null, []);

            self::assertSame(Command::FAILURE, $status, $url);
            self::assertStringContainsString('Refused', $output);
        }

        self::assertSame([], $this->requested);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM source_records'));
    }

    public function test_a_site_that_closes_itself_to_robots_is_not_read(): void
    {
        $routes = ['https://www.beispiel-venue.example/robots.txt' => "User-agent: *\nDisallow: /\n", 'https://www.beispiel-venue.example/' => self::HOME];

        [$status, $output] = $this->runCommand('https://www.beispiel-venue.example/', null, $routes);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('robots', $output);
        self::assertSame(['https://www.beispiel-venue.example/robots.txt'], $this->requested);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM source_records'));
    }

    public function test_an_unknown_venue_slug_stops_before_any_request(): void
    {
        [$status, $output] = $this->runCommand('https://www.beispiel-venue.example/', 'gibt-es-nicht', ['https://www.beispiel-venue.example/' => self::HOME]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No venue with the slug', $output);
        self::assertSame([], $this->requested);
    }

    /**
     * @param array<string, string> $routes URL => body; robots.txt answers 404 unless listed
     *
     * @return array{int, string}
     */
    private function runCommand(string $url, ?string $venue, array $routes): array
    {
        $clock = new MockClock('2026-11-07 10:00:00');
        $client = new MockHttpClient(function (string $method, string $requestUrl) use ($routes): MockResponse {
            $this->requested[] = $requestUrl;
            if (!isset($routes[$requestUrl])) {
                return new MockResponse('not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]);
            }

            return new MockResponse($routes[$requestUrl], ['response_headers' => ['content-type' => str_ends_with($requestUrl, '/robots.txt') ? 'text/plain' : 'text/html; charset=utf-8']]);
        });
        $command = new ImportSiteCommand(
            new SiteImporter(new SafeFetcher($client, (new StubHostResolver())->byDefault(StubHostResolver::PUBLIC_V4), $clock, 'test-agent'), new SitePageParser(), $clock),
            $this->service(VenueDetailQuery::class),
            $this->service(EnabledLocale::class),
            $this->service(MessageBusInterface::class),
        );
        $output = new BufferedOutput();

        $status = $command(new SymfonyStyle(new ArrayInput([]), $output), $url, $venue);

        return [$status, $output->fetch()];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
