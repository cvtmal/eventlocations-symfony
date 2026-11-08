<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\FetchLimits;
use App\Shared\Http\FetchPolicy;
use App\Shared\Http\RefusalReason;
use App\Shared\Http\SafeFetcher;
use App\Tests\Support\Http\StubHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The hostile-URL set of G3 and the caps of SafeFetcher, against a MockHttpClient and a stub DNS: no test here can
 * reach a network. Each route is [status, body, headers]; a URL nobody registered answers 404.
 */
final class SafeFetcherTest extends TestCase
{
    private const string PUBLIC = StubHostResolver::PUBLIC_V4;

    private MockClock $clock;

    /** @var list<array{url: string, options: array<string, mixed>, at: float}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-07 10:00:00');
        $this->requests = [];
    }

    /**
     * @return array{int, string, array<string, string>}
     */
    private static function page(string $body = '<html><body>Hallo</body></html>', string $type = 'text/html; charset=utf-8'): array
    {
        return [200, $body, ['content-type' => $type]];
    }

    /**
     * @return array{int, string, array<string, string>}
     */
    private static function redirect(string $to, int $status = 302): array
    {
        return [$status, '', ['location' => $to]];
    }

    /**
     * @return array{int, string, array<string, string>}
     */
    private static function text(string $body): array
    {
        return [200, $body, ['content-type' => 'text/plain']];
    }

    /**
     * @return array{int, string, array<string, string>}
     */
    private static function answer(int $status): array
    {
        return [$status, 'x', ['content-type' => 'text/html']];
    }

    /**
     * @param array<string, array{int, string|iterable<string>, array<string, string>}> $routes by absolute URL
     * @param array<string, mixed>                                                      $info   extra response info, such as primary_ip
     */
    private function fetcher(array $routes = [], ?StubHostResolver $resolver = null, ?FetchLimits $limits = null, array $info = []): SafeFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($routes, $info): MockResponse {
            $this->requests[] = ['url' => $url, 'options' => $options, 'at' => (float) $this->clock->now()->format('U.u')];
            [$status, $body, $headers] = $routes[$url] ?? [404, 'not found', ['content-type' => 'text/html']];

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers] + $info);
        });

        return new SafeFetcher(
            $client,
            $resolver ?? (new StubHostResolver())->byDefault(self::PUBLIC),
            $this->clock,
            'test-agent (+mailto:hallo@example.ch)',
            $limits ?? new FetchLimits(),
            new FetchPolicy(),
        );
    }

    private static function assertRefused(RefusalReason $reason, callable $call): FetchRefused
    {
        try {
            $call();
        } catch (FetchRefused $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }

        self::fail('Expected a refusal for '.$reason->value.'.');
    }

    /**
     * @return list<float> when each request went out, in order
     */
    private function times(): array
    {
        return array_map(static fn (array $request): float => $request['at'], $this->requests);
    }

    /**
     * @return list<string>
     */
    private function requestedUrls(): array
    {
        return array_map(static fn (array $request): string => $request['url'], $this->requests);
    }

    public function test_a_page_is_fetched_over_the_vetted_address_and_sends_nothing_but_our_name(): void
    {
        $resolver = (new StubHostResolver())->host('www.example.ch', [StubHostResolver::PUBLIC_V6, self::PUBLIC]);
        $fetcher = $this->fetcher(['https://www.example.ch/' => self::page('<p>Hallo</p>', 'text/html; charset=ISO-8859-1')], $resolver);

        $result = $fetcher->open()->get('https://www.example.ch/');

        self::assertSame('<p>Hallo</p>', $result->body);
        self::assertSame('ISO-8859-1', $result->charset);
        self::assertSame('https://www.example.ch/', (string) $result->url);
        self::assertSame(['https://www.example.ch/robots.txt', 'https://www.example.ch/'], $this->requestedUrls());

        foreach ($this->requests as $request) {
            $options = $request['options'];
            self::assertSame(0, $options['max_redirects'], 'the client never follows a redirect');
            self::assertSame(['www.example.ch' => self::PUBLIC], $options['resolve'], 'pinned to the vetted address, IPv4 first');
            self::assertSame('*', $options['no_proxy']);
            self::assertEqualsCanonicalizing(['user-agent', 'accept', 'accept-language', 'accept-encoding'], array_keys($options['normalized_headers']), 'no cookie, no authorization');
            self::assertSame(['Accept-Encoding: identity'], $options['normalized_headers']['accept-encoding'], 'a compressed body could inflate far beyond the cap');
            self::assertSame(8.0, $options['timeout'], 'idle timeout');
            self::assertSame(15.0, $options['max_duration'], 'time one request may take');
            self::assertFalse($options['buffer'], 'the body is streamed and cut off, never buffered whole');
            self::assertSame(['User-Agent: test-agent (+mailto:hallo@example.ch)'], $options['normalized_headers']['user-agent']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'loopback with a port' => ['http://127.0.0.1:8080/'];
        yield 'metadata address' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv4-mapped ipv6 loopback' => ['http://[::ffff:7f00:1]/'];
        yield 'private network' => ['https://192.168.1.1/'];
        yield 'userinfo' => ['http://user:pass@example.ch/'];
        yield 'user that looks like a host' => ['http://example.ch@127.0.0.1/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://example.ch:70/'];
        yield 'ftp' => ['ftp://example.ch/'];
        yield 'decimal ip' => ['http://2130706433/'];
        yield 'localhost' => ['http://localhost/'];
        yield 'not an url' => ['not a url'];
    }

    #[DataProvider('hostileUrls')]
    public function test_a_hostile_url_is_refused_before_anything_is_sent(string $url): void
    {
        $resolver = (new StubHostResolver())->byDefault(self::PUBLIC);

        try {
            $this->fetcher([], $resolver)->open()->get($url);
            self::fail('Expected a refusal.');
        } catch (FetchRefused) {
            self::assertSame([], $this->requests);
            self::assertSame(0, $resolver->totalCalls(), 'not even DNS is asked');
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function hostileAnswers(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'metadata' => [['169.254.169.254']];
        yield 'ipv6 loopback' => [['::1']];
        yield 'ipv4-mapped loopback' => [['::ffff:7f00:1']];
        yield 'private' => [['10.0.0.5']];
        yield 'cgnat' => [['100.64.1.1']];
        yield 'unique local' => [['fd00::1']];
        yield 'link-local ipv6' => [['fe80::1']];
        yield 'public first, private second in one answer' => [[self::PUBLIC, '10.0.0.5']];
        yield 'private first, public second' => [['192.168.0.1', self::PUBLIC]];
        yield 'public ipv4 with a loopback ipv6' => [[self::PUBLIC, '::1']];
    }

    /**
     * @param list<string> $answer
     */
    #[DataProvider('hostileAnswers')]
    public function test_a_host_name_that_resolves_to_a_non_public_address_is_refused(array $answer): void
    {
        $resolver = (new StubHostResolver())->host('venue.example', $answer);

        $this->assertRefused(RefusalReason::BlockedAddress, fn () => $this->fetcher([], $resolver)->open()->get('https://venue.example/'));

        self::assertSame([], $this->requests, 'no request went out');
    }

    public function test_a_host_without_an_address_is_refused(): void
    {
        $this->assertRefused(RefusalReason::Unresolvable, fn () => $this->fetcher([], (new StubHostResolver())->byDefault())->open()->get('https://nowhere.example/'));
    }

    public function test_only_the_default_ports_are_allowed(): void
    {
        $resolver = (new StubHostResolver())->byDefault(self::PUBLIC);
        $session = $this->fetcher([], $resolver)->open();

        $this->assertRefused(RefusalReason::Port, static fn () => $session->get('https://example.ch:8443/'));
        $this->assertRefused(RefusalReason::Port, static fn () => $session->get('http://example.ch:22/'));
        self::assertSame(0, $resolver->calls('example.ch'));
        self::assertSame([], $this->requests);
    }

    public function test_a_dns_rebinding_answer_cannot_swap_the_address(): void
    {
        // The name server answers a public address to the first question and a private one afterwards.
        $resolver = (new StubHostResolver())->host('rebind.example', [self::PUBLIC], ['10.0.0.5']);
        $fetcher = $this->fetcher(['https://rebind.example/' => self::page()], $resolver);

        $this->assertRefused(RefusalReason::BlockedAddress, static fn () => $fetcher->open()->get('https://rebind.example/'));

        self::assertSame(['https://rebind.example/robots.txt'], $this->requestedUrls(), 'only robots.txt went out, to the vetted address');
        self::assertSame(['rebind.example' => self::PUBLIC], $this->requests[0]['options']['resolve']);
        self::assertSame(2, $resolver->calls('rebind.example'), 'every request resolves again and vets again');
    }

    public function test_a_connection_to_another_address_than_the_vetted_one_is_aborted(): void
    {
        $fetcher = $this->fetcher(['https://example.ch/' => self::page()], null, null, ['primary_ip' => '10.0.0.5']);

        $this->assertRefused(RefusalReason::AddressChanged, static fn () => $fetcher->open()->get('https://example.ch/'));
    }

    public function test_a_connection_to_another_vetted_address_of_the_host_is_not_a_change(): void
    {
        // DNS rotates its answers and the client reuses a keep-alive connection made to the first address.
        $resolver = (new StubHostResolver())->host('example.ch', ['93.184.216.34', '93.184.216.35'], ['93.184.216.35', '93.184.216.34']);
        $fetcher = $this->fetcher(['https://example.ch/' => self::page()], $resolver, null, ['primary_ip' => '93.184.216.34']);

        self::assertSame(200, $fetcher->open()->get('https://example.ch/')->status);
        self::assertSame(['example.ch' => '93.184.216.34'], $this->requests[0]['options']['resolve']);
        self::assertSame(['example.ch' => '93.184.216.35'], $this->requests[1]['options']['resolve']);
    }

    public function test_a_public_address_that_was_never_vetted_is_a_change_too(): void
    {
        $fetcher = $this->fetcher(['https://example.ch/' => self::page()], null, null, ['primary_ip' => '8.8.8.8']);

        $this->assertRefused(RefusalReason::AddressChanged, static fn () => $fetcher->open()->get('https://example.ch/'));
    }

    public function test_the_same_address_in_another_spelling_is_not_a_change(): void
    {
        $resolver = (new StubHostResolver())->host('example.ch', ['2606:2800:220:1:248:1893:25c8:1946']);
        $fetcher = $this->fetcher(['https://example.ch/' => self::page()], $resolver, null, ['primary_ip' => '2606:2800:0220:0001:0248:1893:25c8:1946']);

        self::assertSame(200, $fetcher->open()->get('https://example.ch/')->status);
    }

    /**
     * @return iterable<string, array{string, RefusalReason}>
     */
    public static function hostileRedirects(): iterable
    {
        yield 'to the metadata address' => ['http://169.254.169.254/latest/meta-data/', RefusalReason::BlockedAddress];
        yield 'to loopback' => ['http://127.0.0.1:8080/admin', RefusalReason::BlockedAddress];
        yield 'to ipv6 loopback' => ['http://[::1]/', RefusalReason::BlockedAddress];
        yield 'to a name that resolves to a private address' => ['https://intranet.example/', RefusalReason::BlockedAddress];
        yield 'to a file' => ['file:///etc/passwd', RefusalReason::Scheme];
        yield 'to gopher' => ['gopher://example.ch/_x', RefusalReason::Scheme];
        yield 'to javascript' => ['javascript:alert(1)', RefusalReason::Scheme];
        yield 'with credentials' => ['https://user:pw@example.ch/x', RefusalReason::Credentials];
        yield 'to another port' => ['https://example.ch:6379/', RefusalReason::Port];
        yield 'to a scheme-relative loopback' => ['//127.0.0.1/', RefusalReason::BlockedAddress];
        yield 'to a bad host' => ['https://localhost/', RefusalReason::HostName];
    }

    #[DataProvider('hostileRedirects')]
    public function test_a_redirect_is_vetted_like_a_new_url(string $location, RefusalReason $reason): void
    {
        $resolver = (new StubHostResolver())->byDefault(self::PUBLIC)->host('intranet.example', ['10.1.1.1']);
        $fetcher = $this->fetcher(['https://example.ch/' => self::redirect($location)], $resolver);

        $this->assertRefused($reason, static fn () => $fetcher->open()->get('https://example.ch/'));

        self::assertNotContains('http://169.254.169.254/latest/meta-data/', $this->requestedUrls());
        self::assertNotContains('http://127.0.0.1:8080/admin', $this->requestedUrls());
        self::assertCount(2, $this->requests, 'robots.txt and the redirecting page, nothing beyond');
    }

    public function test_redirects_are_followed_up_to_the_cap_and_no_further(): void
    {
        $routes = [
            'https://example.ch/a' => self::redirect('/b'),
            'https://example.ch/b' => self::redirect('/c'),
            'https://example.ch/c' => self::redirect('/d'),
            'https://example.ch/d' => self::page('<p>end</p>'),
            'https://example.ch/e' => self::redirect('/f'),
            'https://example.ch/f' => self::redirect('/g'),
            'https://example.ch/g' => self::redirect('/h'),
            'https://example.ch/h' => self::redirect('/i'),
            'https://example.ch/i' => self::page(),
        ];

        $result = $this->fetcher($routes)->open()->get('https://example.ch/a');
        self::assertSame('https://example.ch/d', (string) $result->url);
        self::assertSame(['https://example.ch/a', 'https://example.ch/b', 'https://example.ch/c'], $result->redirects);

        $this->requests = [];
        $this->assertRefused(RefusalReason::Redirect, fn () => $this->fetcher($routes)->open()->get('https://example.ch/e'));
        self::assertNotContains('https://example.ch/i', $this->requestedUrls(), 'the fourth redirect is not followed');
    }

    public function test_a_redirect_loop_and_a_redirect_without_a_target_are_refused(): void
    {
        $routes = [
            'https://example.ch/a' => self::redirect('/b'),
            'https://example.ch/b' => self::redirect('/a'),
            'https://example.ch/none' => [301, '', []],
        ];

        $this->assertRefused(RefusalReason::Redirect, fn () => $this->fetcher($routes)->open()->get('https://example.ch/a'));
        $this->assertRefused(RefusalReason::Redirect, fn () => $this->fetcher($routes)->open()->get('https://example.ch/none'));
    }

    public function test_the_first_page_may_move_to_another_public_host_and_the_crawl_stays_there(): void
    {
        $routes = [
            'http://venue.example/' => self::redirect('https://www.venue.example/start'),
            'https://www.venue.example/start' => self::page(),
            'https://www.venue.example/raeume' => self::page('<p>Raeume</p>'),
            'https://venue.example/kontakt' => self::page('<p>Kontakt</p>'),
            'https://other.example/' => self::page(),
        ];
        $session = $this->fetcher($routes)->open();

        self::assertSame('https://www.venue.example/start', (string) $session->get('http://venue.example/')->url);
        self::assertSame('<p>Raeume</p>', $session->get('https://www.venue.example/raeume')->body);
        self::assertSame('<p>Kontakt</p>', $session->get('https://venue.example/kontakt')->body, 'the bare domain is the same site');
        $this->assertRefused(RefusalReason::CrossHost, static fn () => $session->get('https://other.example/'));
        self::assertNotContains('https://other.example/', $this->requestedUrls());
    }

    public function test_a_later_page_may_not_redirect_off_the_site(): void
    {
        $routes = [
            'https://venue.example/' => self::page(),
            'https://venue.example/weg' => self::redirect('https://evil.example/'),
        ];
        $session = $this->fetcher($routes)->open();
        $session->get('https://venue.example/');

        $this->assertRefused(RefusalReason::CrossHost, static fn () => $session->get('https://venue.example/weg'));
        self::assertNotContains('https://evil.example/robots.txt', $this->requestedUrls());
    }

    public function test_a_body_over_the_cap_is_cut_off(): void
    {
        $limits = new FetchLimits(maxBytesPerResponse: 10_000);
        $routes = ['https://example.ch/big' => self::page(str_repeat('a', 30_000))];

        $this->assertRefused(RefusalReason::TooLarge, fn () => $this->fetcher($routes, null, $limits)->open()->get('https://example.ch/big'));
    }

    public function test_an_announced_size_over_the_cap_is_refused_before_the_body_is_read(): void
    {
        $limits = new FetchLimits(maxBytesPerResponse: 100);
        $routes = ['https://example.ch/big' => [200, str_repeat('a', 500), ['content-type' => 'text/html', 'content-length' => '500']]];

        $refusal = $this->assertRefused(RefusalReason::TooLarge, fn () => $this->fetcher($routes, null, $limits)->open()->get('https://example.ch/big'));

        self::assertStringContainsString('announces 500 bytes', $refusal->getMessage());
    }

    public function test_the_total_bytes_of_a_crawl_are_capped(): void
    {
        $limits = new FetchLimits(maxBytesPerResponse: 1_000, maxTotalBytes: 1_500);
        $routes = [
            'https://example.ch/' => self::page(str_repeat('a', 800)),
            'https://example.ch/b' => self::page(str_repeat('b', 800)),
        ];
        $session = $this->fetcher($routes, null, $limits)->open();

        $session->get('https://example.ch/');
        $this->assertRefused(RefusalReason::ByteBudget, static fn () => $session->get('https://example.ch/b'));
        self::assertSame(800, $session->bytes());
    }

    public function test_the_pages_of_a_crawl_are_capped(): void
    {
        $limits = new FetchLimits(maxPages: 3);
        $routes = [];
        foreach (['/', '/a', '/b', '/c'] as $path) {
            $routes['https://example.ch'.$path] = self::page();
        }
        $session = $this->fetcher($routes, null, $limits)->open();

        $session->get('https://example.ch/');
        $session->get('https://example.ch/a');
        $session->get('https://example.ch/b');
        $this->assertRefused(RefusalReason::PageLimit, static fn () => $session->get('https://example.ch/c'));
        self::assertNotContains('https://example.ch/c', $this->requestedUrls());
        self::assertSame(3, $session->pages());
    }

    public function test_the_time_of_a_crawl_is_capped_and_the_pauses_count(): void
    {
        $limits = new FetchLimits(totalSeconds: 2.5, minDelaySeconds: 1.0);
        $routes = [];
        foreach (['/', '/a', '/b', '/c'] as $path) {
            $routes['https://example.ch'.$path] = self::page();
        }
        $session = $this->fetcher($routes, null, $limits)->open();

        $session->get('https://example.ch/');
        $session->get('https://example.ch/a');
        $this->assertRefused(RefusalReason::TimeLimit, static fn () => $session->get('https://example.ch/b'));
    }

    /**
     * @return iterable<string, array{array{int, string, array<string, string>}, RefusalReason}>
     */
    public static function unacceptableAnswers(): iterable
    {
        yield 'an image' => [[200, 'x', ['content-type' => 'image/png']], RefusalReason::ContentType];
        yield 'a pdf' => [[200, 'x', ['content-type' => 'application/pdf']], RefusalReason::ContentType];
        yield 'json' => [[200, '{}', ['content-type' => 'application/json']], RefusalReason::ContentType];
        yield 'no content type' => [[200, 'x', []], RefusalReason::ContentType];
        yield 'not found' => [[404, 'x', ['content-type' => 'text/html']], RefusalReason::HttpStatus];
        yield 'forbidden' => [[403, 'x', ['content-type' => 'text/html']], RefusalReason::HttpStatus];
        yield 'too many requests' => [[429, 'x', ['content-type' => 'text/html']], RefusalReason::HttpStatus];
        yield 'server error' => [[503, 'x', ['content-type' => 'text/html']], RefusalReason::HttpStatus];
    }

    /**
     * @param array{int, string, array<string, string>} $answer
     */
    #[DataProvider('unacceptableAnswers')]
    public function test_an_answer_that_is_not_an_html_page_is_refused(array $answer, RefusalReason $reason): void
    {
        $this->assertRefused($reason, fn () => $this->fetcher(['https://example.ch/' => $answer])->open()->get('https://example.ch/'));
    }

    public function test_the_status_code_of_a_refusal_is_kept(): void
    {
        $refusal = $this->assertRefused(RefusalReason::HttpStatus, fn () => $this->fetcher(['https://example.ch/' => self::answer(410)])->open()->get('https://example.ch/'));

        self::assertSame(410, $refusal->statusCode);
    }

    public function test_a_connection_failure_is_a_refusal(): void
    {
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/robots.txt')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse('', ['error' => 'Connection refused']));
        $fetcher = new SafeFetcher($client, (new StubHostResolver())->byDefault(self::PUBLIC), $this->clock, 'agent');

        $this->assertRefused(RefusalReason::Transport, static fn () => $fetcher->open()->get('https://example.ch/'));
    }

    public function test_robots_txt_can_disallow_a_path(): void
    {
        $routes = [
            'https://example.ch/robots.txt' => self::text("User-agent: *\nDisallow: /intern\nAllow: /intern/oeffentlich\n"),
            'https://example.ch/' => self::page(),
            'https://example.ch/intern/oeffentlich' => self::page(),
            'https://example.ch/intern/geheim' => self::page(),
        ];
        $session = $this->fetcher($routes)->open();

        self::assertSame(200, $session->get('https://example.ch/')->status);
        self::assertSame(200, $session->get('https://example.ch/intern/oeffentlich')->status);
        $this->assertRefused(RefusalReason::Robots, static fn () => $session->get('https://example.ch/intern/geheim'));
        self::assertNotContains('https://example.ch/intern/geheim', $this->requestedUrls());
        self::assertCount(1, array_filter($this->requestedUrls(), static fn (string $url): bool => str_ends_with($url, '/robots.txt')), 'robots.txt is read once per host');
    }

    public function test_a_group_for_our_name_beats_the_wildcard_group(): void
    {
        $routes = [
            'https://example.ch/robots.txt' => self::text("User-agent: *\nDisallow: /\n\nUser-agent: Deine-Eventlocation\nAllow: /\nDisallow: /privat\n"),
            'https://example.ch/' => self::page(),
        ];

        self::assertSame(200, $this->fetcher($routes)->open()->get('https://example.ch/')->status);
    }

    public function test_the_whole_site_can_be_closed(): void
    {
        $routes = ['https://example.ch/robots.txt' => self::text("User-agent: *\nDisallow: /\n"), 'https://example.ch/' => self::page()];

        $this->assertRefused(RefusalReason::Robots, fn () => $this->fetcher($routes)->open()->get('https://example.ch/'));
        self::assertSame(['https://example.ch/robots.txt'], $this->requestedUrls());
    }

    public function test_a_missing_robots_txt_allows_everything_and_an_unreadable_one_nothing(): void
    {
        $this->assertRefused(RefusalReason::Robots, fn () => $this->fetcher(['https://example.ch/robots.txt' => self::answer(500), 'https://example.ch/' => self::page()])->open()->get('https://example.ch/'));
        $this->assertRefused(RefusalReason::Robots, fn () => $this->fetcher(['https://example.ch/robots.txt' => self::answer(429), 'https://example.ch/' => self::page()])->open()->get('https://example.ch/'));
        $this->assertRefused(RefusalReason::Robots, fn () => $this->fetcher(['https://example.ch/robots.txt' => self::text(str_repeat('#', 600_000)), 'https://example.ch/' => self::page()])->open()->get('https://example.ch/'));
        self::assertSame(200, $this->fetcher(['https://example.ch/robots.txt' => self::answer(404), 'https://example.ch/' => self::page()])->open()->get('https://example.ch/')->status);
        self::assertSame(200, $this->fetcher(['https://example.ch/robots.txt' => self::answer(403), 'https://example.ch/' => self::page()])->open()->get('https://example.ch/')->status);
    }

    public function test_a_robots_txt_that_redirects_to_a_private_address_is_a_blocked_address_not_a_robots_matter(): void
    {
        $routes = ['https://example.ch/robots.txt' => self::redirect('http://169.254.169.254/robots.txt'), 'https://example.ch/' => self::page()];

        $this->assertRefused(RefusalReason::BlockedAddress, fn () => $this->fetcher($routes)->open()->get('https://example.ch/'));
    }

    public function test_requests_to_one_host_are_a_second_apart_and_a_crawl_delay_widens_the_gap(): void
    {
        $routes = [
            'https://example.ch/robots.txt' => self::text("User-agent: *\nCrawl-delay: 4\n"),
            'https://example.ch/' => self::page(),
            'https://example.ch/a' => self::page(),
        ];
        $session = $this->fetcher($routes)->open();
        $session->get('https://example.ch/');
        $session->get('https://example.ch/a');

        $times = $this->times();
        self::assertCount(3, $times);
        self::assertGreaterThanOrEqual(4.0, $times[1] - $times[0]);
        self::assertGreaterThanOrEqual(4.0, $times[2] - $times[1]);

        $this->requests = [];
        $plain = $this->fetcher(['https://example.ch/' => self::page(), 'https://example.ch/a' => self::page()])->open();
        $plain->get('https://example.ch/');
        $plain->get('https://example.ch/a');
        $times = $this->times();
        self::assertGreaterThanOrEqual(1.0, $times[1] - $times[0]);
        self::assertGreaterThanOrEqual(1.0, $times[2] - $times[1]);
    }

    public function test_a_crawl_delay_above_our_limit_refuses_the_host(): void
    {
        $routes = ['https://example.ch/robots.txt' => self::text("User-agent: *\nCrawl-delay: 120\n"), 'https://example.ch/' => self::page()];

        $this->assertRefused(RefusalReason::Robots, fn () => $this->fetcher($routes)->open()->get('https://example.ch/'));
    }

    public function test_a_server_that_stops_sending_is_a_refusal(): void
    {
        // An empty chunk is how MockResponse simulates an idle timeout.
        $routes = ['https://example.ch/' => [200, ['<p>start', '', 'rest</p>'], ['content-type' => 'text/html']]];

        $refusal = $this->assertRefused(RefusalReason::Transport, fn () => $this->fetcher($routes)->open()->get('https://example.ch/'));

        self::assertStringContainsString('stopped sending', $refusal->detail);
    }

    public function test_robots_txt_of_a_redirect_target_is_obeyed(): void
    {
        $routes = [
            'http://a.example/' => self::redirect('https://www.a.example/start'),
            'https://www.a.example/robots.txt' => self::text("User-agent: *\nDisallow: /start\n"),
            'https://www.a.example/start' => self::page(),
        ];

        $this->assertRefused(RefusalReason::Robots, fn () => $this->fetcher($routes)->open()->get('http://a.example/'));
        self::assertNotContains('https://www.a.example/start', $this->requestedUrls());
    }

    public function test_what_a_server_says_never_reaches_a_refusal_unprinted(): void
    {
        $answers = [
            'control characters' => "text/\x1b[31mhtml\x07",
            'invalid utf-8' => "text/\xff\xfehtml",
            'bidi override' => "text/\u{202E}html",
        ];
        foreach ($answers as $label => $type) {
            $refusal = $this->assertRefused(RefusalReason::ContentType, fn () => $this->fetcher(['https://example.ch/' => [200, 'x', ['content-type' => $type]]])->open()->get('https://example.ch/'));

            self::assertSame(0, preg_match('/[\x00-\x1f\x7f]/', $refusal->detail), $label);
            self::assertTrue(mb_check_encoding($refusal->detail, 'UTF-8'), $label);
            self::assertNotFalse(json_encode(['detail' => $refusal->detail], \JSON_THROW_ON_ERROR), $label);
            self::assertStringNotContainsString("\u{202E}", $refusal->detail, $label);
        }
    }

    public function test_the_local_fixture_policy_is_the_only_way_to_reach_a_private_address(): void
    {
        $resolver = (new StubHostResolver())->host('fixtures.example', ['127.0.0.1']);
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('<p>ok</p>', ['response_headers' => ['content-type' => 'text/html']]));

        $strict = new SafeFetcher($client, $resolver, $this->clock, 'agent');
        $this->assertRefused(RefusalReason::BlockedAddress, static fn () => $strict->open()->get('http://fixtures.example/'));

        $relaxed = new SafeFetcher($client, $resolver, $this->clock, 'agent', policy: FetchPolicy::unsafeForLocalFixtures());
        self::assertSame('<p>ok</p>', $relaxed->open()->get('http://fixtures.example:8099/')->body);
    }

    public function test_a_resource_is_fetched_without_robots_txt_and_with_the_callers_headers(): void
    {
        $fetcher = $this->fetcher(['https://api.example.ch/v1/thing' => [200, '{"a":1}', ['content-type' => 'application/json; charset=utf-8']]]);

        $result = $fetcher->open()->resource('https://api.example.ch/v1/thing', ['application/json'], ['X-Api-Key' => 'secret']);

        self::assertSame('{"a":1}', $result->body);
        self::assertSame('application/json', $result->contentType);
        self::assertSame(['https://api.example.ch/v1/thing'], $this->requestedUrls(), 'no robots.txt for an API');
        $options = $this->requests[0]['options'];
        self::assertSame(['X-Api-Key: secret'], $options['normalized_headers']['x-api-key']);
        self::assertSame(['Accept: application/json'], $options['normalized_headers']['accept']);
        self::assertSame(['api.example.ch' => self::PUBLIC], $options['resolve'], 'pinned like a page');
    }

    public function test_a_redirect_of_a_resource_drops_the_callers_headers(): void
    {
        $fetcher = $this->fetcher([
            'https://api.example.ch/photo' => self::redirect('https://images.example.net/p.jpg'),
            'https://images.example.net/p.jpg' => [200, 'JPEG', ['content-type' => 'image/jpeg']],
        ]);

        $result = $fetcher->open()->resource('https://api.example.ch/photo', ['image/jpeg'], ['X-Api-Key' => 'secret']);

        self::assertSame('JPEG', $result->body);
        self::assertSame(['https://api.example.ch/photo', 'https://images.example.net/p.jpg'], $this->requestedUrls(), 'another host is fine, robots.txt is not asked');
        self::assertArrayHasKey('x-api-key', $this->requests[0]['options']['normalized_headers']);
        self::assertArrayNotHasKey('x-api-key', $this->requests[1]['options']['normalized_headers'], 'the key never follows a redirect');
    }

    public function test_a_resource_keeps_the_address_checks_type_list_and_request_cap(): void
    {
        $resolver = (new StubHostResolver())->byDefault(self::PUBLIC)->host('internal.example', ['10.0.0.5']);
        $fetcher = $this->fetcher([
            'https://api.example.ch/html' => self::page(),
            'https://api.example.ch/ok' => [200, '{}', ['content-type' => 'application/json']],
        ], $resolver, new FetchLimits(maxPages: 1));

        $this->assertRefused(RefusalReason::BlockedAddress, fn () => $this->fetcher([], $resolver)->open()->resource('https://internal.example/x', ['application/json']));
        $this->assertRefused(RefusalReason::ContentType, fn () => $this->fetcher(['https://api.example.ch/html' => self::page()])->open()->resource('https://api.example.ch/html', ['application/json']));

        $session = $fetcher->open();
        $session->resource('https://api.example.ch/ok', ['application/json']);
        $this->assertRefused(RefusalReason::PageLimit, static fn () => $session->resource('https://api.example.ch/ok', ['application/json']));
    }
}
