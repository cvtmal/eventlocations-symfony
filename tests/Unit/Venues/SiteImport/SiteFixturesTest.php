<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues\SiteImport;

use App\Shared\Http\SafeFetcher;
use App\Tests\Support\Http\StubHostResolver;
use App\Venues\SiteImport\SiteImporter;
use App\Venues\SiteImport\SiteImportResult;
use App\Venues\SiteImport\SitePageParser;
use App\Venues\ValueObject\ImageCandidate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Crawls the invented venue websites in `tests/Fixtures/SiteImport/<NN-name>/` with a MockHttpClient and a stub DNS
 * (nothing leaves the process) and compares what the importer returns with the `expect` block of the site's
 * `site.json`. Every request the importer makes is recorded.
 *
 * ```
 * description  one line
 * entry        the URL the crawl starts with
 * routes       what the fake server answers: "/path?query" on the entry's origin (a route may name its "host" and
 *              "scheme"), or a full URL. A spec is {"file": "x.html"}, {"body": "..."} or {"redirect": "<Location>"},
 *              with an optional "status" and "headers" (default content-type: text/html; charset=utf-8). Any URL
 *              without a route answers 404, which for /robots.txt means "everything allowed".
 * expect       facts                  {"name": "x", "address.postal_code": "8000"}: the best (first) observation of the key
 *              has_fact_values        {"heading": ["x"]}: values that must be among the observations of the key
 *              absent_fact_values     {"name": ["x"]}: values that must not be
 *              min_candidates, max_candidates, candidate_urls_contain, candidate_urls_absent
 *              candidates_matching    [{"pattern": "~regex~", "count": 1}]: how many candidate URLs match
 *              candidate_origins      {"<url>": "css_background"}
 *              min_skipped_images, pages (count), page_urls_contain, final_url, redirects
 *              refused                [{"path": "/galerie", "reason": "robots"}]; no entry means nothing was refused
 *              no_requests_containing ["127.0.0.1"]: no requested URL contains these
 * known_gaps   [{"reason": "...", "checks": ["facts.name", ...]}]: checks that fail today because the importer is
 *              wrong, not the fixture. They stay in the file, the test is reported as incomplete, and it fails as soon
 *              as one of them passes, so the entry is removed with the fix.
 * ```
 *
 * Besides the `expect` block, every site must pass the `universal.*` checks: http(s) URLs only, no IP or localhost
 * hosts, no repeated request, no request outside the site, no PHP warning from src/, no mojibake, no photo twice.
 *
 * @phpstan-type Spec array<string, mixed>
 * @phpstan-type Site array{description: string, entry: string, routes: array<string, Spec>, expect: array<string, mixed>, known_gaps?: list<array{reason: string, checks: list<string>}>}
 */
final class SiteFixturesTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/SiteImport';

    /**
     * @return iterable<string, array{string}>
     */
    public static function sites(): iterable
    {
        $files = glob(self::FIXTURES.'/*/site.json') ?: [];
        if ([] === $files) {
            throw new \LogicException('No site.json found below '.self::FIXTURES);
        }

        foreach ($files as $file) {
            yield basename(\dirname($file)) => [\dirname($file)];
        }
    }

    public function test_every_fixture_folder_has_a_site_file(): void
    {
        $folders = glob(self::FIXTURES.'/*', \GLOB_ONLYDIR) ?: [];

        self::assertGreaterThanOrEqual(10, \count($folders));
        foreach ($folders as $folder) {
            self::assertFileExists($folder.'/site.json');
        }
    }

    #[DataProvider('sites')]
    public function test_the_site_is_read_as_its_fixture_expects(string $directory): void
    {
        /** @var Site $site */
        $site = json_decode((string) file_get_contents($directory.'/site.json'), true, 512, \JSON_THROW_ON_ERROR);
        $routes = self::routes($site);
        foreach ($routes as $spec) {
            if (isset($spec['file'])) {
                self::assertFileExists($directory.'/'.$spec['file']);
            }
        }

        $requested = [];
        $warnings = [];
        $result = $this->crawl($directory, $site['entry'], $routes, $requested, $warnings);

        $failures = [
            ...self::universalChecks($site, $result, $requested, $warnings),
            ...self::factChecks($site['expect'], $result),
            ...self::candidateChecks($site['expect'], $result),
            ...self::pageChecks($site['expect'], $result, $requested),
        ];

        $gaps = [];
        foreach ($site['known_gaps'] ?? [] as $gap) {
            foreach ($gap['checks'] as $check) {
                $gaps[$check] = $gap['reason'];
            }
        }

        self::assertSame([], array_diff_key($failures, $gaps), 'The importer does not return what the fixture expects.');
        self::assertSame([], array_keys(array_diff_key($gaps, $failures)), 'These known gaps no longer fail: remove them from known_gaps in site.json.');

        if ([] !== $failures) {
            $lines = [];
            foreach ($failures as $check => $message) {
                $lines[] = \sprintf('%s: %s (%s)', $check, $message, $gaps[$check]);
            }
            self::markTestIncomplete("Known gaps of the importer:\n".implode("\n", $lines));
        }
    }

    /**
     * @param Site $site
     *
     * @return array<string, Spec> by absolute URL
     */
    private static function routes(array $site): array
    {
        $entry = parse_url($site['entry']);
        $routes = [];
        foreach ($site['routes'] as $key => $spec) {
            if (str_starts_with($key, '/')) {
                $scheme = \is_string($spec['scheme'] ?? null) ? $spec['scheme'] : ($entry['scheme'] ?? 'https');
                $host = \is_string($spec['host'] ?? null) ? $spec['host'] : ($entry['host'] ?? '');
                $key = $scheme.'://'.$host.$key;
            }
            $routes[$key] = $spec;
        }

        return $routes;
    }

    /**
     * @param array<string, Spec> $routes
     * @param list<string>        $requested every URL the importer asked for, in order
     * @param list<string>        $warnings  what PHP raised inside src/ while it ran
     */
    private function crawl(string $directory, string $entry, array $routes, array &$requested, array &$warnings): SiteImportResult
    {
        $client = new MockHttpClient(static function (string $method, string $url) use ($routes, $directory, &$requested): MockResponse {
            $requested[] = $url;
            $spec = $routes[$url] ?? null;
            if (null === $spec) {
                return new MockResponse('not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html; charset=utf-8']]);
            }
            if (isset($spec['redirect'])) {
                return new MockResponse('', ['http_code' => $spec['status'] ?? 301, 'response_headers' => ['location' => $spec['redirect']]]);
            }

            $body = isset($spec['file']) ? (string) file_get_contents($directory.'/'.$spec['file']) : (string) ($spec['body'] ?? '');
            $headers = array_change_key_case(\is_array($spec['headers'] ?? null) ? $spec['headers'] : [], \CASE_LOWER) + ['content-type' => 'text/html; charset=utf-8'];

            return new MockResponse($body, ['http_code' => $spec['status'] ?? 200, 'response_headers' => $headers]);
        });

        $clock = new MockClock('2026-11-07 10:00:00');
        $fetcher = new SafeFetcher($client, (new StubHostResolver())->byDefault(StubHostResolver::PUBLIC_V4), $clock, 'test-agent (+mailto:hallo@example.ch)');
        $importer = new SiteImporter($fetcher, new SitePageParser(), $clock);

        // PHPUnit would fail the whole test on a warning from src/; here it is one more check, so that a known gap can name it.
        $source = \dirname(__DIR__, 4).'/src/';
        $previous = set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$warnings, &$previous, $source): bool {
            // An @ leaves only the levels it cannot silence; PHPUnit has already narrowed error_reporting() itself.
            $silenced = 0 === (error_reporting() & ~(\E_ERROR | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR | \E_PARSE));
            if (!$silenced && str_starts_with($file, $source)) {
                $warnings[] = \sprintf('%s (%s:%d)', $message, substr($file, \strlen($source)), $line);

                return true;
            }

            return null !== $previous && false !== $previous($level, $message, $file, $line);
        });

        try {
            return $importer->import($entry);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param Site         $site
     * @param list<string> $requested
     * @param list<string> $warnings
     *
     * @return array<string, string> check => what is wrong
     */
    private static function universalChecks(array $site, SiteImportResult $result, array $requested, array $warnings): array
    {
        $failures = [];

        if ([] !== $warnings) {
            $failures['universal.no_php_warnings'] = implode('; ', $warnings);
        }

        // An import never returns a javascript:, data: or file: URL; both URLs are plain http(s) and name a host, not an address.
        foreach ($result->candidates as $candidate) {
            foreach ([$candidate->imageUrl, $candidate->pageUrl] as $url) {
                if (1 !== preg_match('~^https?://~', $url) || !self::isNamedHost($url)) {
                    $failures['universal.candidate_urls_are_public_http'] = $url;
                }
            }
            if (!\in_array($candidate->pageUrl, array_column($result->pages, 'url'), true)) {
                $failures['universal.candidates_come_from_pages_that_were_read'] = $candidate->imageUrl.' on '.$candidate->pageUrl;
            }
        }
        if (\count($result->candidates) > SiteImporter::MAX_IMAGES || \count($result->pages) > SiteImporter::MAX_PAGES) {
            $failures['universal.limits'] = \count($result->candidates).' candidates, '.\count($result->pages).' pages';
        }

        $images = array_map(static fn (ImageCandidate $candidate): string => $candidate->imageUrl, $result->candidates);
        if ($images !== array_values(array_unique($images))) {
            $failures['universal.no_duplicate_candidates'] = implode(', ', array_diff_assoc($images, array_unique($images)));
        }
        // WordPress writes photo.jpg and photo-300x200.jpg; the importer is meant to keep one of them.
        $photos = array_map(static fn (string $url): string => (string) preg_replace('/-\d{2,4}x\d{2,4}(?=\.\w+$)/', '', $url), $images);
        if ($photos !== array_values(array_unique($photos))) {
            $failures['universal.no_duplicate_photos'] = implode(', ', array_diff_assoc($photos, array_unique($photos)));
        }

        // The observations of a key are listed best first, with a confidence between 0 and 1.
        foreach ($result->facts as $key => $observations) {
            $previous = 1.0;
            foreach ($observations as $observation) {
                if ($observation['confidence'] < 0.0 || $observation['confidence'] > $previous || '' === $observation['via'] || 1 !== preg_match('~^https?://~', $observation['source_url'])) {
                    $failures['universal.facts_are_well_formed'] = $key.': '.self::show($observation);
                }
                $previous = $observation['confidence'];
            }
        }
        // UTF-8 read as Latin-1 turns "ü" into "Ã¼".
        $texts = [self::show($result->facts), ...array_map(static fn (ImageCandidate $candidate): string => $candidate->alt.' '.$candidate->context, $result->candidates)];
        foreach ($texts as $text) {
            if (1 === preg_match('/[\x{00C2}\x{00C3}][\x{0080}-\x{00BF}]/u', $text)) {
                $failures['universal.no_mojibake'] = mb_substr($text, 0, 200);
            }
        }

        // Nothing is asked for twice, and nothing outside the hosts the fixture knows (a link to an IP or to another site).
        $hosts = array_map(static fn (string $url): string => self::siteKey($url), array_keys(self::routes($site)));
        foreach ($requested as $url) {
            if (!self::isNamedHost($url) || !\in_array(self::siteKey($url), $hosts, true)) {
                $failures['universal.requests_stay_on_the_site'] = $url;
            }
        }
        $repeated = array_keys(array_filter(array_count_values($requested), static fn (int $times): bool => $times > 1));
        if ([] !== $repeated) {
            $failures['universal.no_repeated_requests'] = implode(', ', $repeated);
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $expect
     *
     * @return array<string, string>
     */
    private static function factChecks(array $expect, SiteImportResult $result): array
    {
        $failures = [];

        foreach (self::map($expect, 'facts') as $path => $expected) {
            $parts = explode('.', (string) $path, 2);
            $best = $result->facts[$parts[0]][0]['value'] ?? null;
            $actual = isset($parts[1]) ? (\is_array($best) ? $best[$parts[1]] ?? null : null) : $best;
            if (!self::equal($expected, $actual)) {
                $failures['facts.'.$path] = 'expected '.self::show($expected).', got '.self::show($actual);
            }
        }

        foreach (self::map($expect, 'has_fact_values') as $key => $values) {
            $actual = array_column($result->facts[$key] ?? [], 'value');
            $missing = array_filter((array) $values, static fn (mixed $value): bool => !self::contains($actual, $value));
            if ([] !== $missing) {
                $failures['has_fact_values.'.$key] = 'missing '.self::show(array_values($missing)).' among '.self::show($actual);
            }
        }

        foreach (self::map($expect, 'absent_fact_values') as $key => $values) {
            $actual = array_column($result->facts[$key] ?? [], 'value');
            $present = array_filter((array) $values, static fn (mixed $value): bool => self::contains($actual, $value));
            if ([] !== $present) {
                $failures['absent_fact_values.'.$key] = 'found '.self::show(array_values($present));
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $expect
     *
     * @return array<string, string>
     */
    private static function candidateChecks(array $expect, SiteImportResult $result): array
    {
        $failures = [];
        $urls = array_map(static fn (ImageCandidate $candidate): string => $candidate->imageUrl, $result->candidates);

        if (isset($expect['min_candidates']) && \count($urls) < $expect['min_candidates']) {
            $failures['min_candidates'] = 'expected at least '.$expect['min_candidates'].', got '.\count($urls);
        }
        if (isset($expect['max_candidates']) && \count($urls) > $expect['max_candidates']) {
            $failures['max_candidates'] = 'expected at most '.$expect['max_candidates'].', got '.\count($urls);
        }
        if (isset($expect['min_skipped_images']) && $result->skippedImages < $expect['min_skipped_images']) {
            $failures['min_skipped_images'] = 'expected at least '.$expect['min_skipped_images'].', got '.$result->skippedImages;
        }

        $missing = array_diff(self::list($expect, 'candidate_urls_contain'), $urls);
        if ([] !== $missing) {
            $failures['candidate_urls_contain'] = 'missing '.self::show(array_values($missing));
        }
        $present = array_intersect(self::list($expect, 'candidate_urls_absent'), $urls);
        if ([] !== $present) {
            $failures['candidate_urls_absent'] = 'found '.self::show(array_values($present));
        }

        foreach (self::map($expect, 'candidates_matching') as $entry) {
            $entry = (array) $entry;
            $matching = array_values(array_filter($urls, static fn (string $url): bool => 1 === preg_match((string) $entry['pattern'], $url)));
            if (\count($matching) !== $entry['count']) {
                $failures['candidates_matching '.$entry['pattern']] = 'expected '.$entry['count'].', got '.\count($matching).' '.self::show($matching);
            }
        }

        foreach (self::map($expect, 'candidate_origins') as $url => $origin) {
            $found = array_values(array_filter($result->candidates, static fn (ImageCandidate $candidate): bool => $candidate->imageUrl === (string) $url));
            if ([] === $found || $found[0]->origin->value !== $origin) {
                $failures['candidate_origins'] = $url.' expected as '.self::show($origin).', got '.([] === $found ? 'no candidate' : $found[0]->origin->value);
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $expect
     * @param list<string>         $requested
     *
     * @return array<string, string>
     */
    private static function pageChecks(array $expect, SiteImportResult $result, array $requested): array
    {
        $failures = [];
        $read = array_column($result->pages, 'url');

        if (isset($expect['pages']) && \count($read) !== $expect['pages']) {
            $failures['pages'] = 'expected '.$expect['pages'].', got '.\count($read).' '.self::show($read);
        }
        $missing = array_diff(self::list($expect, 'page_urls_contain'), $read);
        if ([] !== $missing) {
            $failures['page_urls_contain'] = 'missing '.self::show(array_values($missing)).' among '.self::show($read);
        }
        if (isset($expect['final_url']) && $result->finalUrl !== $expect['final_url']) {
            $failures['final_url'] = 'expected '.$expect['final_url'].', got '.$result->finalUrl;
        }
        if (isset($expect['redirects']) && $result->redirects !== $expect['redirects']) {
            $failures['redirects'] = 'expected '.self::show($expect['redirects']).', got '.self::show($result->redirects);
        }

        $refused = array_map(static fn (array $entry): string => $entry['reason'].' '.(string) parse_url($entry['url'], \PHP_URL_PATH), $result->refused);
        $expected = array_map(static fn (mixed $entry): string => ((array) $entry)['reason'].' '.((array) $entry)['path'], self::map($expect, 'refused'));
        sort($refused);
        sort($expected);
        if ($refused !== $expected) {
            $failures['refused'] = 'expected '.self::show($expected).', got '.self::show($refused);
        }

        foreach (self::list($expect, 'no_requests_containing') as $needle) {
            $hits = array_values(array_filter($requested, static fn (string $url): bool => str_contains($url, $needle)));
            if ([] !== $hits) {
                $failures['no_requests_containing '.$needle] = 'requested '.self::show($hits);
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $expect
     *
     * @return array<int|string, mixed>
     */
    private static function map(array $expect, string $key): array
    {
        return \is_array($expect[$key] ?? null) ? $expect[$key] : [];
    }

    /**
     * @param array<string, mixed> $expect
     *
     * @return list<string>
     */
    private static function list(array $expect, string $key): array
    {
        return array_values(array_map(strval(...), self::map($expect, $key)));
    }

    /**
     * @param array<mixed> $values
     */
    private static function contains(array $values, mixed $expected): bool
    {
        foreach ($values as $value) {
            if (self::equal($expected, $value)) {
                return true;
            }
        }

        return false;
    }

    private static function equal(mixed $expected, mixed $actual): bool
    {
        if (\is_array($expected) && \is_array($actual)) {
            ksort($expected);
            ksort($actual);

            return $expected === $actual;
        }
        if (\is_float($expected) || \is_float($actual)) {
            return is_numeric($expected) && is_numeric($actual) && abs((float) $expected - (float) $actual) < 0.000001;
        }

        return $expected === $actual;
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** A host name, not an IP address, not localhost. */
    private static function isNamedHost(string $url): bool
    {
        $host = trim((string) parse_url($url, \PHP_URL_HOST), '[]');

        return '' !== $host && 'localhost' !== $host && false === filter_var($host, \FILTER_VALIDATE_IP);
    }

    /** `example.ch` for `https://www.example.ch/x`: the bare domain and www are one site. */
    private static function siteKey(string $url): string
    {
        return (string) preg_replace('/^www\./', '', (string) parse_url($url, \PHP_URL_HOST));
    }
}
